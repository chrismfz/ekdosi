<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerUser;
use App\Models\Invoice;
use App\Models\Quote;
use App\Support\Pdf\PdfLabels;

/**
 * The ONE place that decides which language a customer-facing surface speaks
 * (i18n Slice 0). Distinct signals, deliberately NOT conflated:
 *
 *   • {@see forPortal()}   — the portal CHROME (el/en only). A logged-in user's
 *                            `customer_users.locale` preference wins; otherwise the
 *                            portal host's tenant ({@see forHost()}) decides — so
 *                            guest pages AND null-locale users on a custom host get
 *                            that tenant's language. A login maps to many customers
 *                            via a grant table, so there is NO per-customer language
 *                            to borrow for the chrome.
 *   • {@see forCustomer()} — the COMMUNICATION language of a customer: their explicit
 *                            preference, else derived from their country, else the
 *                            tenant default. `el`/`en`/`both`.
 *   • {@see forDocumentMail()} — the language of the EMAIL that carries a document.
 *                            The mail goes TO the linked customer, so it tracks that
 *                            customer (a transient message, unlike a legal PDF), then
 *                            collapses bilingual → English (one language per body).
 *
 * The PDF is deliberately NOT resolved here: its language stays FROZEN on the
 * document (`invoices/quotes.language` → snapshotted country, via PdfLabels in the
 * renderers), so a re-rendered legal document never changes language. The
 * per-customer language reaches a PDF only by being stamped onto the document at
 * issue — a later slice — or via the operator's per-doc «Γλώσσα PDF» Select.
 *
 * Resolution tail (first usable wins): explicit choice → recipient country (GR→el,
 * foreign→both) → company default → el. The country tier only fires when a country
 * is actually known, so the company default (e.g. the Estonian tenant's) is
 * reachable for a country-less party.
 */
final class CustomerLanguage
{
    /** Languages a document/mail may be rendered in (the single whitelist). */
    public const DOCUMENT = PdfLabels::LANGUAGES;

    /** Languages the portal UI can switch to (no bilingual chrome). */
    public const UI = ['el', 'en'];

    public const FALLBACK = 'el';

    /**
     * Portal UI locale for a logged-in customer user: their stored preference,
     * else the app default. Clamped to the {@see UI} set ('both' is meaningless
     * for chrome). Accepts null (guest/edge) → app default.
     */
    public static function forPortal(?CustomerUser $user, ?Company $hostCompany = null): string
    {
        // A logged-in user's explicit UI preference wins…
        if (in_array($user?->locale, self::UI, true)) {
            return $user->locale;
        }

        // …otherwise the portal host's tenant language — this covers BOTH guest
        // pages AND a logged-in user who has no explicit locale yet (e.g. a freshly
        // invited Nixpal customer), so they don't land on Greek after an English
        // login. forHost() falls back to the app default when there is no host.
        return self::forHost($hostCompany);
    }

    /**
     * Portal UI locale for a GUEST page (login/reset) resolved from the portal
     * host's tenant (#1c). The chrome is single-language: a tenant whose default
     * is bilingual ('both') gets English guest chrome (its foreign-facing case).
     * No host / no tenant default → the app default (clamped to the UI set).
     */
    public static function forHost(?Company $company): string
    {
        return match ($company?->default_language) {
            'el' => 'el',
            'en', 'both' => 'en',
            default => self::appDefaultUi(),
        };
    }

    /** The app default locale, clamped to the portal UI set (el/en). */
    private static function appDefaultUi(): string
    {
        $app = (string) config('app.locale', self::FALLBACK);

        return in_array($app, self::UI, true) ? $app : self::FALLBACK;
    }

    /**
     * Single language for an EMAIL addressed to a customer (statement, ticket) —
     * {@see forCustomer()} collapsed to one language (both→en), since an email body
     * is single-language. Twin of {@see forDocumentMail()} for customer-level mail.
     */
    public static function forCustomerMail(Customer $customer): string
    {
        $language = self::forCustomer($customer);

        return $language === 'both' ? 'en' : $language;
    }

    /**
     * Communication language for a customer: their explicit override, else derived
     * from their (live) country, else the tenant default, else Greek.
     */
    public static function forCustomer(Customer $customer): string
    {
        if (in_array($customer->language, self::DOCUMENT, true)) {
            return $customer->language;
        }

        // isoCountryCode() prefers the country_code cache but falls back to
        // normalising the free-text `country`, so a customer whose cache was never
        // backfilled still resolves correctly (matches the model's own resolver).
        return self::fromCountryOrCompany($customer->isoCountryCode(), $customer->company);
    }

    /**
     * Language for a document's EMAIL. The mail is addressed to the linked customer,
     * so it tracks that customer's preference ({@see forCustomer()}) — NOT the
     * document's «Γλώσσα PDF» override, which is a PDF-only choice. Falls back to the
     * document's snapshotted country/tenant default when there is no linked customer.
     * Collapses bilingual to English (an email body is one language). Plumbing for
     * the email-i18n slice.
     */
    public static function forDocumentMail(Invoice|Quote $document): string
    {
        $customer = $document->customer;

        $language = $customer !== null
            ? self::forCustomer($customer)
            : self::fromCountryOrCompany($document->country, $document->company);

        return $language === 'both' ? 'en' : $language;
    }

    /**
     * Shared tail: a known country decides (GR/empty → el, any other → both, via
     * PdfLabels — the single source of that rule); an EMPTY/unknown country falls
     * through to the tenant default; nothing usable → Greek.
     */
    private static function fromCountryOrCompany(?string $country, ?Company $company): string
    {
        // Emptiness only here; PdfLabels::resolveLanguage does the upper/trim + the
        // GR-vs-foreign decision, so that logic lives in exactly one place.
        if (trim((string) $country) !== '') {
            return PdfLabels::resolveLanguage(null, $country);
        }

        $default = $company?->default_language;

        return in_array($default, self::DOCUMENT, true) ? $default : self::FALLBACK;
    }
}
