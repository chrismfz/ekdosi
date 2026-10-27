<?php

namespace App\Support\Billing;

/**
 * The billing source's name in the operator's sentences: a row from MyCS («MyCS Bridge», docs/bridges-connectors.md
 * §12) never reads «WHMCS» (found in the live test of 2026-10-27). Greek articles go with it: «τη MyCS», «το WHMCS».
 */
final class SourceName
{
    private function __construct(private readonly bool $mycs) {}

    public static function for(bool $mycs): self
    {
        return new self($mycs);
    }

    /** «MyCS» / «WHMCS». */
    public function label(): string
    {
        return $this->mycs ? 'MyCS' : 'WHMCS';
    }

    /** «τη MyCS» / «το WHMCS». */
    public function the(): string
    {
        return $this->mycs ? 'τη MyCS' : 'το WHMCS';
    }

    /** «Η MyCS» / «Το WHMCS», at the start of a sentence. */
    public function subject(): string
    {
        return $this->mycs ? 'Η MyCS' : 'Το WHMCS';
    }

    /** «της MyCS» / «του WHMCS». */
    public function of(): string
    {
        return $this->mycs ? 'της MyCS' : 'του WHMCS';
    }

    /** «στη MyCS» / «στο WHMCS». */
    public function in(): string
    {
        return $this->mycs ? 'στη MyCS' : 'στο WHMCS';
    }

    /** «MyCS #7» / «WHMCS #7». */
    public function ref(int|string|null $id): string
    {
        return $this->label().' #'.$id;
    }
}
