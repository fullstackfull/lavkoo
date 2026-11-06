<?php

namespace App\Enums\OperationalCosts;

enum ContractStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    /** A newer version took over. Its rules stay readable: costs computed under it keep pointing at them. */
    case Superseded = 'superseded';
    case Archived = 'archived';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function labelKey(): string
    {
        return 'contract_status_' . $this->value;
    }
}
