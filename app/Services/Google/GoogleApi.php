<?php

namespace App\Services\Google;

/**
 * Google APIs used with the agent account. Each data method is exactly one
 * API request (plus an access-token refresh when the token expired).
 *
 * - OAuth 2.0: accounts.google.com/o/oauth2/v2/auth, oauth2.googleapis.com/token
 * - GA4 Admin API v1beta: accountSummaries, properties, dataStreams, customDimensions
 * - GA4 Data API v1beta: properties/{id}:runReport
 * - Search Console API webmasters/v3: sites, searchAnalytics/query
 *
 * HttpGoogleApi calls Google; FakeGoogleApi serves local development and tests.
 */
interface GoogleApi
{
    public function authorizationUrl(string $state): string;

    /**
     * Exchange the OAuth code; email is the signed-in account (openid + email scopes).
     *
     * @return array{refresh_token: ?string, access_token: string, expires_in: int, scope: string, email: ?string, email_verified: bool}
     *
     * @throws GoogleApiException
     */
    public function exchangeCode(string $code): array;

    /**
     * Revoke a token (used when the wrong account signed in).
     */
    public function revoke(string $token): void;

    /**
     * GA4 properties shared with the agent.
     *
     * @return list<array{property: string, displayName: string, account: string}>
     */
    public function properties(): array;

    /**
     * Web stream URLs of a GA4 property ("properties/123").
     *
     * @return list<string>
     */
    public function webStreamUrls(string $property): array;

    /**
     * @return array{timeZone: ?string, currencyCode: ?string}
     */
    public function propertyDetails(string $property): array;

    /**
     * @return list<array{parameterName: string, displayName: string, scope: string}>
     */
    public function customDimensions(string $property): array;

    /**
     * GA4 Data API runReport; the raw response (rows, rowCount, propertyQuota).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function runReport(string $property, array $body): array;

    /**
     * Search Console sites shared with the agent.
     *
     * @return list<array{siteUrl: string, permissionLevel: string}>
     */
    public function sites(): array;

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function searchAnalytics(string $siteUrl, array $body): array;
}
