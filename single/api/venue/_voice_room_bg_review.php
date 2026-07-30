<?php
// 语音房背景图审核的 Redis 存储约定。
// 审核记录与上传冷却均放在 DB6，不依赖新增数据表。
require_once __DIR__ . '/../RedisHelper.php';

class VoiceRoomBgReviewStore
{
    const DB_INDEX = 6;
    const TTL_SECONDS = 604800; // 7 天
    const REVIEW_POOL_KEY = 'venue_voice_bg_review_pool';

    private $redis;

    public function __construct()
    {
        $this->redis = new RedisHelper();
        $this->redis->connect();
        $this->redis->selectDb(self::DB_INDEX);
    }

    public static function reviewKey($venueId)
    {
        return 'venue_voice_bg_review:' . intval($venueId);
    }

    public static function cooldownKey($venueId)
    {
        return 'venue_voice_bg_upload_lock:' . intval($venueId);
    }

    public function getReview($venueId)
    {
        $raw = $this->redis->get(self::reviewKey($venueId));
        if ($raw === false || $raw === null || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public function getReviewTtl($venueId)
    {
        return intval($this->redis->ttl(self::reviewKey($venueId)));
    }

    public function getCooldown($venueId)
    {
        $key = self::cooldownKey($venueId);
        $ttl = intval($this->redis->ttl($key));
        if ($ttl <= 0) {
            return [
                'locked' => false,
                'ttl' => $ttl,
                'until_ts' => null,
                'until_iso' => null
            ];
        }

        $raw = $this->redis->get($key);
        $data = $raw ? json_decode($raw, true) : [];
        if (!is_array($data)) {
            $data = [];
        }

        $untilTs = time() + $ttl;
        $data['locked'] = true;
        $data['ttl'] = $ttl;
        $data['until_ts'] = $untilTs;
        $data['until_iso'] = date('Y-m-d H:i:s', $untilTs);
        return $data;
    }

    /**
     * 原子抢占一周上传资格。并发上传时只有一个请求能够成功。
     */
    public function acquireCooldown($venueId, array $payload)
    {
        $key = self::cooldownKey($venueId);
        $value = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $native = $this->redis->getNative();

        $result = $native->set($key, $value, [
            'nx',
            'ex' => self::TTL_SECONDS
        ]);

        return $result === true || $result === 'OK';
    }

    public function releaseCooldown($venueId)
    {
        return $this->redis->delete(self::cooldownKey($venueId));
    }

    /**
     * 待审核记录默认不设置过期时间，避免审核超过 7 天后记录丢失并遗留孤儿文件。
     * 审批/驳回后可由审核接口传入 TTL_SECONDS，仅保留 7 天结果供提交人查看。
     */
    public function saveReview($venueId, array $review, $ttl = 0)
    {
        $key = self::reviewKey($venueId);
        $this->redis->save(
            $key,
            json_encode($review, JSON_UNESCAPED_UNICODE),
            max(0, intval($ttl))
        );
        $this->redis->getNative()->sAdd(self::REVIEW_POOL_KEY, $key);
        return true;
    }

    public function removeFromPool($venueId)
    {
        return $this->redis->getNative()->sRem(
            self::REVIEW_POOL_KEY,
            self::reviewKey($venueId)
        );
    }

    public function close()
    {
        $this->redis->close();
    }
}
