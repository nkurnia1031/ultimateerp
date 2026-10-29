<?php

namespace Modules\Accounting\Services;

class JournalAccountTypeResolver
{
    public static function fromGlCode($glCode)
    {
        $prefix = substr(trim((string) $glCode), 0, 1);

        if ($prefix === '1') {
            return 'asset';
        }

        if ($prefix === '2') {
            return 'liability';
        }

        if ($prefix === '3') {
            return 'equity';
        }

        if ($prefix === '4') {
            return 'income';
        }

        return 'expenses';
    }
}
