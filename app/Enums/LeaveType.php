<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Leave kinds, valued with the ΕΡΓΑΝΗ ΙΙ «Οργάνωση Χρόνου Εργασίας – Άδειες»
 * codes (official guide §4, docs/ergani/) so a later WTOLeave submission sends
 * `f_type` verbatim. Full-day leaves only; the hourly «ωροάδειες» (ΩΑ*) and the
 * rarer statutory kinds fall under «Άλλη άδεια» (ΑΔΑΛ) until needed.
 */
enum LeaveType: string implements HasColor, HasLabel
{
    case Annual = 'ΑΔΚΑΝ';
    case Sick = 'ΑΔΑΣ';
    case Unpaid = 'ΑΔΑΑ';
    case Marriage = 'ΑΔΓΑΜ';
    case Bereavement = 'ΑΔΘΣΥΓ';
    case Exams = 'ΑΔΕΞ';
    case BloodDonation = 'ΑΔΑΙΜ';
    case Maternity = 'ΑΔΜΗ';
    case Paternity = 'ΑΔΠΑ';
    case Childcare = 'ΑΔΦΠ';
    case Parental = 'ΑΔΓΟΝ';
    case ChildSickness = 'ΑΔΑΠΕΜ';
    case Other = 'ΑΔΑΛ';

    public function getLabel(): string
    {
        return match ($this) {
            self::Annual => 'Κανονική άδεια',
            self::Sick => 'Άδεια ασθένειας',
            self::Unpaid => 'Άδεια άνευ αποδοχών',
            self::Marriage => 'Άδεια γάμου',
            self::Bereavement => 'Άδεια λόγω θανάτου συγγενούς',
            self::Exams => 'Άδεια εξετάσεων',
            self::BloodDonation => 'Αιμοδοτική άδεια',
            self::Maternity => 'Άδεια μητρότητας',
            self::Paternity => 'Άδεια πατρότητας',
            self::Childcare => 'Άδεια φροντίδας παιδιού',
            self::Parental => 'Γονική άδεια',
            self::ChildSickness => 'Άδεια ασθένειας παιδιού/εξαρτώμενου',
            self::Other => 'Άλλη άδεια',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Annual => 'success',
            self::Sick, self::ChildSickness => 'danger',
            self::Unpaid => 'gray',
            default => 'info',
        };
    }

    /** Only κανονική άδεια counts against the yearly entitlement. */
    public function consumesAnnualBalance(): bool
    {
        return $this === self::Annual;
    }
}
