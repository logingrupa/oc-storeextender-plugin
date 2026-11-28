<?php

declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Helper;

/**
 * Request-scoped container state carrying trusted server-resolved order attribution
 * and headless API execution flags. Never populated from raw browser POST input.
 */
final class OrderAttributionContext
{
    public const SESSION_KEY = 'storeextender.order_attribution';
    public const PROJECTION_TABLE = 'logingrupa_storeextender_order_attributions';

    public const CHANNEL_WEB = 'web';
    public const CHANNEL_AI_REFERRAL = 'ai_referral';
    public const CHANNEL_AGENT_BROWSER = 'agent_browser';
    public const CHANNEL_AGENT_API = 'agent_api';
    public const CHANNEL_AGENT_B2B = 'agent_b2b';
    public const CHANNEL_BACKEND = 'backend';
    public const CHANNEL_CONSOLE = 'console';

    /** @var list<string> */
    public const ALLOWED_CHANNEL_LIST = [
        self::CHANNEL_WEB,
        self::CHANNEL_AI_REFERRAL,
        self::CHANNEL_AGENT_BROWSER,
        self::CHANNEL_AGENT_API,
        self::CHANNEL_AGENT_B2B,
        self::CHANNEL_BACKEND,
        self::CHANNEL_CONSOLE,
    ];

    private const CLIENT_SLUG_PATTERN = '/\A[a-z0-9_-]{1,40}\z/';

    public ?string $sOrderChannel = null;

    public ?string $sAgentClient = null;

    public bool $bVerified = false;

    public bool $bSkipReplayMemory = false;

    public ?int $iHandoffId = null;

    public ?int $iAllowedCorrectionOrderId = null;

    public function set(
        string $sOrderChannel,
        ?string $sAgentClient = null,
        bool $bVerified = false,
        bool $bSkipReplayMemory = false,
        ?int $iHandoffId = null
    ): void {
        if (!in_array($sOrderChannel, self::ALLOWED_CHANNEL_LIST, true)) {
            throw new \InvalidArgumentException('Unsupported order channel: ' . $sOrderChannel);
        }

        $this->sOrderChannel = $sOrderChannel;
        $this->sAgentClient = self::sanitizeClientSlug($sAgentClient);
        $this->bVerified = $bVerified;
        $this->bSkipReplayMemory = $bSkipReplayMemory;
        $this->iHandoffId = $iHandoffId !== null && $iHandoffId > 0 ? $iHandoffId : null;
    }

    public function clear(): void
    {
        $this->sOrderChannel = null;
        $this->sAgentClient = null;
        $this->bVerified = false;
        $this->bSkipReplayMemory = false;
        $this->iHandoffId = null;
        $this->iAllowedCorrectionOrderId = null;
    }

    public function hasAttribution(): bool
    {
        return $this->sOrderChannel !== null;
    }

    public static function sanitizeClientSlug(mixed $mValue): ?string
    {
        if (!is_string($mValue)) {
            return null;
        }

        $sNormalized = strtolower(trim($mValue));

        return preg_match(self::CLIENT_SLUG_PATTERN, $sNormalized) === 1 ? $sNormalized : null;
    }
}
