<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Rebuild;

use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;

final readonly class SwapNaming
{
    private const int MAX_IDENTIFIER_LENGTH = 63;

    private const string TEMP_INFIX = '__mv_tmp_';

    private const string OLD_INFIX = '__mv_old_';

    private const string TEMP_INDEX_INFIX = '__tmp_';

    private function __construct(
        private string $baseName,
        private string $token,
    ) {
    }

    public static function for(MaterializedViewName $view, string $swapToken): self
    {
        $token = self::sanitizeToken($swapToken);

        return new self($view->name, $token);
    }

    public function temporaryViewName(): string
    {
        return $this->compose(self::TEMP_INFIX);
    }

    public function oldViewName(): string
    {
        return $this->compose(self::OLD_INFIX);
    }

    public function temporaryIndexName(string $finalIndexName): string
    {
        $suffix = self::TEMP_INDEX_INFIX.$this->token;
        $budget = self::MAX_IDENTIFIER_LENGTH - \strlen($suffix);

        return self::truncate($finalIndexName, $budget).$suffix;
    }

    private function compose(string $infix): string
    {
        $suffix = $infix.$this->token;
        $budget = self::MAX_IDENTIFIER_LENGTH - \strlen($suffix);

        return self::truncate($this->baseName, $budget).$suffix;
    }

    private static function truncate(string $value, int $budget): string
    {
        if ($budget <= 0) {
            return '';
        }

        return substr($value, 0, $budget);
    }

    private static function sanitizeToken(string $token): string
    {
        $sanitized = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $token));

        if ('' === $sanitized) {
            return '0';
        }

        return substr($sanitized, 0, 16);
    }
}
