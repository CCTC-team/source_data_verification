<?php

namespace CCTC\SourceDataVerificationModule\Classes;

/**
 * A rule violation whose message is safe to show to the user (e.g. "close all queries first").
 */
class SdvException extends \RuntimeException
{
}
