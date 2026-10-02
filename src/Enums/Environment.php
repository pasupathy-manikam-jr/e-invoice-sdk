<?php

namespace Oriclab\EInvoice\Enums;

enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    public function portalUrl(): string
    {
        return $this === self::Production
            ? 'https://myinvois.hasil.gov.my'
            : 'https://preprod.myinvois.hasil.gov.my';
    }
}
