<?php

namespace App\Services\SalesChannels\Returns;

use RuntimeException;

/**
 * Only some rows of a group could be fenced, so none of them is.
 *
 * It exists to roll a transaction back rather than to be reported. Fencing part of a group and then abandoning the send left
 * those rows in `may_have_sent` -- a state nothing picks up again -- so a customer's return sat undecided for ever while the
 * row looked as though a decision were on its way. Fencing and deciding not to send cannot be two outcomes of one attempt.
 */
final class PartialFence extends RuntimeException
{
}
