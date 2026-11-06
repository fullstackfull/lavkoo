<?php

namespace App\Services\SalesChannels;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Data\ConnectionTestResult;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;

/**
 * Runs the read-only connection test of an account's channel and records what it found (granted and missing permissions, the time). A test creates and changes nothing
 * at the channel. An account that is switched off, or does not hold the Connection Test capability, cannot be tested: the transport refuses, and the refusal is the answer.
 */
class ChannelConnectionTester
{
    public function __construct(
        private readonly ChannelConnectorRegistry $connectors,
        private readonly ChannelAccountService $accounts,
    ) {
    }

    public function test(SalesChannelAccount $account): ConnectionTestResult
    {
        try {
            $result = $this->connectors->for($account)->testConnection($account);
        } catch (ChannelUnavailableException $exception) {
            return ConnectionTestResult::failed($exception->getMessage());
        }
        $this->accounts->recordConnection($account, $result);

        return $result;
    }
}
