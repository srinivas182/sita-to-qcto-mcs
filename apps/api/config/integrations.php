<?php

declare(strict_types=1);

/*
 | Integrations catalogue (Settings → Integrations).
 |
 | Every external service the platform talks to is an "integration" of one type:
 | payment, sms, whatsapp, email or storage. The Super Admin sets one up per driver
 | in Settings: credentials (stored encrypted), sandbox or live, enabled, and the
 | order in which drivers of the same type are tried. Types with `modes` also
 | choose sandbox (test keys) or live.
 |
 | fields: name => [label, secret?, required?, type? (text|url|select|bool|number),
 |                  options? (for select), default?, help?]
 |   secret fields are never returned by the API; only a masked hint is.
 | capabilities: what a driver can do. Billing and Messaging pick drivers by these.
 | note: shown to the Super Admin when setting the driver up.
 | single: only one driver of the type may be enabled at a time (storage).
 |
 | Driver behaviour (charges, sending, uploads) lives in the module that uses the
 | type: Files (storage), Billing (payment) and Messaging (sms, whatsapp, email).
 */

return [
    'types' => [
        'payment' => ['label' => 'Payment gateways', 'single' => false, 'modes' => true],
        'sms' => ['label' => 'SMS', 'single' => false, 'modes' => false],
        'whatsapp' => ['label' => 'WhatsApp', 'single' => false, 'modes' => false],
        'email' => ['label' => 'Email', 'single' => false, 'modes' => false],
        'storage' => ['label' => 'File storage', 'single' => true, 'modes' => false],
    ],

    'drivers' => [
        // ── Payment gateways (South Africa) ──────────────────────────────────
        'payment.paystack' => [
            'label' => 'Paystack',
            'description' => 'Cards, Instant EFT, Capitec Pay and subscriptions. A Stripe company.',
            'capabilities' => ['card', 'recurring', 'instant_eft'],
            'fields' => [
                'public_key' => ['label' => 'Public key', 'required' => true],
                'secret_key' => ['label' => 'Secret key', 'secret' => true, 'required' => true],
            ],
        ],
        'payment.payfast' => [
            'label' => 'PayFast',
            'description' => 'Cards, Instant EFT, SnapScan, Zapper and subscriptions by tokenisation.',
            'capabilities' => ['card', 'recurring', 'instant_eft'],
            'fields' => [
                'merchant_id' => ['label' => 'Merchant ID', 'required' => true],
                'merchant_key' => ['label' => 'Merchant key', 'secret' => true, 'required' => true],
                'passphrase' => ['label' => 'Passphrase', 'secret' => true, 'required' => true],
            ],
        ],
        'payment.peach' => [
            'label' => 'Peach Payments',
            'description' => 'Hosted checkout for cards, EFT and wallets, for larger volumes.',
            'capabilities' => ['card', 'instant_eft'],
            'note' => 'Every result is confirmed with Peach before an invoice is marked paid. Add the webhook URL in the Peach Dashboard and, ideally, switch on webhook signing. Run one sandbox payment before going live.',
            'fields' => [
                'entity_id' => ['label' => 'Entity ID', 'required' => true],
                'client_id' => ['label' => 'Client ID', 'required' => true],
                'client_secret' => ['label' => 'Client secret', 'secret' => true, 'required' => true],
                'merchant_id' => ['label' => 'Merchant ID', 'required' => true],
                'webhook_secret' => ['label' => 'Webhook secret', 'secret' => true],
            ],
        ],
        'payment.ozow' => [
            'label' => 'Ozow',
            'description' => 'Instant EFT from all major South African banks. One-off payments.',
            'capabilities' => ['instant_eft'],
            'note' => 'Sandbox mode sends test requests (IsTest). Run one test payment before going live.',
            'fields' => [
                'site_code' => ['label' => 'Site code', 'required' => true],
                'api_key' => ['label' => 'API key', 'secret' => true, 'required' => true],
                'private_key' => ['label' => 'Private key', 'secret' => true, 'required' => true],
            ],
        ],
        'payment.yoco' => [
            'label' => 'Yoco',
            'description' => 'Online card checkout, popular with small businesses. Minimum R2.',
            'capabilities' => ['card'],
            'note' => 'Register the webhook URL with Yoco and paste the whsec_ secret it returns. Test or live follows the keys.',
            'fields' => [
                'public_key' => ['label' => 'Public key', 'required' => true],
                'secret_key' => ['label' => 'Secret key', 'secret' => true, 'required' => true],
                'webhook_secret' => ['label' => 'Webhook secret', 'secret' => true],
            ],
        ],
        'payment.stitch' => [
            'label' => 'Stitch',
            'description' => 'Pay by bank (with Capitec Pay) and cards through one payment request.',
            'capabilities' => ['card', 'instant_eft'],
            'note' => 'Payments are read back from Stitch before an invoice is marked paid. Add your site to the client\'s redirect allowlist. Test or live follows the client.',
            'fields' => [
                'client_id' => ['label' => 'Client ID', 'required' => true],
                'client_secret' => ['label' => 'Client secret', 'secret' => true, 'required' => true],
                'webhook_secret' => ['label' => 'Webhook secret (whsec_…)', 'secret' => true, 'required' => true],
                'merchant_id' => ['label' => 'Stitch merchant ID', 'help' => 'If Stitch registered one for you. Otherwise fill in the bank account below.'],
                'beneficiary_name' => ['label' => 'Account holder (receives payments)'],
                'beneficiary_bank' => ['label' => 'Bank', 'help' => 'Stitch bank ID, e.g. absa, fnb, nedbank, standard_bank, capitec'],
                'beneficiary_account_number' => ['label' => 'Account number'],
            ],
        ],
        'payment.ikhokha' => [
            'label' => 'iKhokha',
            'description' => 'Payment links for cards and Instant EFT.',
            'capabilities' => ['card', 'instant_eft'],
            'note' => 'iKhokha\'s published signature samples differ slightly; run one test payment and check it is marked paid before going live.',
            'fields' => [
                'application_id' => ['label' => 'Application ID', 'required' => true],
                'application_secret' => ['label' => 'Application secret', 'secret' => true, 'required' => true],
            ],
        ],

        // ── SMS (South Africa) ───────────────────────────────────────────────
        'sms.clickatell' => [
            'label' => 'Clickatell',
            'description' => 'Large SA provider with delivery receipts.',
            'capabilities' => ['send', 'delivery_receipts'],
            'fields' => [
                'api_key' => ['label' => 'API key', 'secret' => true, 'required' => true],
                'sender_id' => ['label' => 'Sender ID', 'help' => 'Optional. Approved alphanumeric sender or long number.'],
            ],
        ],
        'sms.bulksms' => [
            'label' => 'BulkSMS',
            'description' => 'SA-founded provider with simple pricing.',
            'capabilities' => ['send', 'delivery_receipts'],
            'fields' => [
                'token_id' => ['label' => 'Token ID', 'required' => true],
                'token_secret' => ['label' => 'Token secret', 'secret' => true, 'required' => true],
                'sender_id' => ['label' => 'Sender ID'],
            ],
        ],
        'sms.smsportal' => [
            'label' => 'SMSPortal',
            'description' => 'SA provider with local routes and delivery reports.',
            'capabilities' => ['send', 'delivery_receipts'],
            'fields' => [
                'client_id' => ['label' => 'Client ID', 'required' => true],
                'api_secret' => ['label' => 'API secret', 'secret' => true, 'required' => true],
            ],
        ],
        'sms.panacea' => [
            'label' => 'Panacea Mobile',
            'description' => 'SA provider with direct network connections.',
            'capabilities' => ['send', 'delivery_receipts'],
            'fields' => [
                'username' => ['label' => 'Username', 'required' => true],
                'api_key' => ['label' => 'API key', 'secret' => true, 'required' => true],
                'sender_id' => ['label' => 'Sender ID'],
            ],
        ],
        'sms.infobip' => [
            'label' => 'Infobip',
            'description' => 'Global provider with an SA presence.',
            'capabilities' => ['send', 'delivery_receipts'],
            'fields' => [
                'base_url' => ['label' => 'Base URL', 'type' => 'url', 'required' => true, 'help' => 'e.g. https://xxxxx.api.infobip.com'],
                'api_key' => ['label' => 'API key', 'secret' => true, 'required' => true],
                'sender_id' => ['label' => 'Sender ID'],
            ],
        ],
        'sms.twilio' => [
            'label' => 'Twilio',
            'description' => 'Global provider, useful as a last fallback.',
            'capabilities' => ['send', 'delivery_receipts'],
            'fields' => [
                'account_sid' => ['label' => 'Account SID', 'required' => true],
                'auth_token' => ['label' => 'Auth token', 'secret' => true, 'required' => true],
                'from' => ['label' => 'From number or Messaging Service SID', 'required' => true],
            ],
        ],

        // ── WhatsApp ─────────────────────────────────────────────────────────
        'whatsapp.meta' => [
            'label' => 'WhatsApp Cloud API (Meta)',
            'description' => 'Direct from Meta, no reseller markup. Needs a verified business and a dedicated number.',
            'capabilities' => ['send', 'templates', 'authentication', 'delivery_receipts'],
            'fields' => [
                'phone_number_id' => ['label' => 'Phone number ID', 'required' => true],
                'business_account_id' => ['label' => 'WhatsApp Business account ID', 'required' => true],
                'access_token' => ['label' => 'Access token (system user)', 'secret' => true, 'required' => true],
                'app_secret' => ['label' => 'App secret', 'secret' => true, 'required' => true, 'help' => 'Used to check that webhooks come from Meta.'],
                'otp_template' => ['label' => 'Sign-in code template name', 'default' => 'sign_in_code'],
            ],
        ],

        // ── Email ────────────────────────────────────────────────────────────
        'email.smtp' => [
            'label' => 'SMTP',
            'description' => 'Any mail server or provider that offers SMTP.',
            'capabilities' => ['send'],
            'fields' => [
                'host' => ['label' => 'Host', 'required' => true],
                'port' => ['label' => 'Port', 'type' => 'number', 'default' => 587, 'required' => true],
                'encryption' => ['label' => 'Encryption', 'type' => 'select', 'options' => ['tls', 'ssl', 'none'], 'default' => 'tls'],
                'username' => ['label' => 'Username'],
                'password' => ['label' => 'Password', 'secret' => true],
                'from_address' => ['label' => 'From address', 'required' => true],
                'from_name' => ['label' => 'From name'],
            ],
        ],
        'email.ses' => [
            'label' => 'Amazon SES',
            'description' => 'AWS email in Cape Town or another region.',
            'capabilities' => ['send', 'delivery_receipts'],
            'fields' => [
                'key' => ['label' => 'Access key ID', 'required' => true],
                'secret' => ['label' => 'Secret access key', 'secret' => true, 'required' => true],
                'region' => ['label' => 'Region', 'default' => 'af-south-1', 'required' => true],
                'from_address' => ['label' => 'From address', 'required' => true],
                'from_name' => ['label' => 'From name'],
            ],
        ],

        // ── File storage (one active at a time) ──────────────────────────────
        'storage.local' => [
            'label' => 'Local disk',
            'description' => 'Files on the application server. For one-server installs and development.',
            'capabilities' => ['store'],
            'fields' => [
                'root' => ['label' => 'Folder', 'default' => 'files', 'help' => 'Inside the private storage folder.'],
            ],
        ],
        'storage.s3' => [
            'label' => 'Amazon S3',
            'description' => 'AWS S3 (Cape Town by default) or any S3-compatible store.',
            'capabilities' => ['store', 'direct_upload'],
            'fields' => [
                'key' => ['label' => 'Access key ID', 'required' => true],
                'secret' => ['label' => 'Secret access key', 'secret' => true, 'required' => true],
                'region' => ['label' => 'Region', 'default' => 'af-south-1', 'required' => true],
                'bucket' => ['label' => 'Bucket', 'required' => true],
                'prefix' => ['label' => 'Folder prefix', 'help' => 'Optional, e.g. production/'],
                'endpoint' => ['label' => 'Custom endpoint', 'type' => 'url', 'help' => 'Only for S3-compatible stores such as MinIO.'],
                'path_style' => ['label' => 'Path-style URLs', 'type' => 'bool', 'default' => false],
            ],
        ],
        'storage.azure' => [
            'label' => 'Azure Blob Storage',
            'description' => 'Microsoft Azure (South Africa North region recommended).',
            'capabilities' => ['store', 'direct_upload'],
            'fields' => [
                'account_name' => ['label' => 'Storage account name', 'required' => true],
                'account_key' => ['label' => 'Account key', 'secret' => true, 'required' => true],
                'container' => ['label' => 'Container', 'required' => true],
                'prefix' => ['label' => 'Folder prefix'],
                'endpoint' => ['label' => 'Custom endpoint', 'type' => 'url', 'help' => 'Only for Azurite or sovereign clouds.'],
            ],
        ],
    ],
];
