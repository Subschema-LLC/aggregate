<?php

declare(strict_types=1);

namespace App\Entities\ConsentManager;

/**
 * Default wording for builtin and standalone consent banners.
 */
final class ConsentDefaultText
{
    public const BUILTIN = [
        'title' => '{name}: privacy choices',
        'description' => 'Choose which optional categories to allow. Enhanced analytics '
            .'uses browser identifiers and additional event details. Tags in each selected '
            .'category may load third-party scripts.',
        'details' => [
            'Coarse anonymous measurement may continue after rejection. You can change '
            .'your choice here at any time. Withdrawal removes analytics identifiers '
            .'and stops future enhanced detail; it does not erase stored history.',
            'After withdrawal, reload this page to stop optional scripts already loaded. '
            .'Tags configured to require no consent can run regardless of these choices. '
            .'See this website’s privacy notice for its data, purposes, and providers.',
        ],
        'categories_legend' => 'Optional categories',
        'categories' => ['analytics' => 'Enhanced analytics'],
        'category_tags' => 'Includes: {tags}',
        'reject' => 'Reject all optional categories',
        'accept' => 'Accept all optional categories',
        'save' => 'Save selected choices',
        'reopen' => 'Privacy choices',
        'dismiss' => 'Close this notice',
        'dismiss_reject' => 'Reject all optional categories and close',
        'privacy_link' => 'Read this website’s privacy notice',
        'status_applied' => 'Your privacy choices have been applied.',
        'status_not_saved' => 'This choice could not be saved; choose again on your next visit.',
        'status_other_tab' => 'Your privacy choices were updated in another tab.',
    ];

    public const STANDALONE = [
        'title' => '{name}: privacy choices',
        'description' => 'Choose which optional categories to allow. They start denied. '
            .'You can change your choices at any time.',
        'details' => [
            'These choices apply to connected tools. If this website uses privacy-minimized '
            .'analytics, coarse measurement may continue after rejection. '
            .'See its privacy notice for the actual data and providers.',
        ],
        'privacy_link' => 'Read this website’s privacy notice',
        'reject' => 'Reject optional categories',
        'accept' => 'Accept optional categories',
        'manage' => 'Manage choices',
        'save' => 'Save selected choices',
        'reopen' => 'Privacy choices',
        'close' => 'Close privacy settings',
        'dismiss' => 'Close this notice',
        'dismiss_reject' => 'Reject optional categories and close',
        'settings_label' => 'Privacy settings',
        'preferences_tab' => 'Preferences',
        'requests_tab' => 'Privacy request',
        'preferences_intro' => 'Allow only the categories you choose. Rejection and withdrawal '
            .'stop future actions in connected tools. Scripts already loaded may continue '
            .'until you reload, and their cookies and stored history are not automatically erased.',
        'categories_legend' => 'Optional categories',
        'categories' => [
            'analytics' => 'Analytics (enhanced details when connected to the analytics tracker)',
        ],
        'category_tags' => 'Includes: {tags}',
        'opt_out' => 'Opt out of sale, sharing, or targeted advertising in connected tools',
        'opt_out_help' => 'This opt-out disables the marketing category and signals connected '
            .'providers. It cannot enforce choices for tools that are not connected or process '
            .'an organization-wide privacy request.',
        'gpc_notice' => 'Your browser sends Global Privacy Control. We keep the advertising '
            .'opt-out on and marketing denied. This signal does not grant analytics consent.',
        'storage_notice' => 'Your category choices, opt-out, policy revision, and save time are '
            .'stored in this browser for up to {days} days. No visitor identifier is added.',
        'status_applied' => 'Your privacy choices have been applied.',
        'status_not_saved' => 'They could not be saved. Choose again on your next visit.',
        'status_other_tab' => 'Privacy choices were updated in another tab.',
        'request_disclosure' => 'Submitting this form sends your email address, request type, '
            .'and optional message to Formspree for this website’s operator. Nothing is sent until '
            .'you submit. No visitor identifier or page URL is added. Do not include sensitive '
            .'information.',
        'request_email' => 'Email address',
        'request_type' => 'Request type',
        'request_access' => 'Access my data',
        'request_delete' => 'Delete my data',
        'request_correct' => 'Correct my data',
        'request_opt_out' => 'Opt out of sale, sharing, or targeted advertising',
        'request_message' => 'Message (optional, up to 2,000 characters)',
        'request_submit' => 'Submit privacy request',
        'request_invalid' => 'Enter a valid email address and request type, and keep your '
            .'message within 2,000 characters.',
        'request_unavailable' => 'This request could not be sent. Use the contact information '
            .'in this website’s privacy notice.',
        'request_sending' => 'Sending your request to Formspree…',
        'request_failed' => 'Your request could not be submitted. Your entries are still here; '
            .'retry or use the contact information in this website’s privacy notice.',
        'request_sent' => 'Your request was submitted to Formspree for this website’s operator. '
            .'The operator must review and process it; submission does not erase stored data.',
    ];
}
