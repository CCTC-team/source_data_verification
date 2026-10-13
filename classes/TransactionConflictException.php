<?php

namespace CCTC\SourceDataVerificationModule\Classes;

/**
 * MySQL aborted the statement with a deadlock (1213) or lock wait timeout (1205). After a deadlock the
 * server has already rolled back the whole transaction, so only re-running the whole transaction is safe.
 */
class TransactionConflictException extends \RuntimeException
{
}
