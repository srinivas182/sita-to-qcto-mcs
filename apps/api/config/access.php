<?php

declare(strict_types=1);

/*
 | Role and permission catalogue (ADR-009).
 |
 | scope:
 |   platform – held across the whole platform (Super Admin, regulators, ecosystem)
 |   tenant   – held within one training provider
 |   centre   – held within one training provider, optionally limited to centres
 | mfa: true makes an authenticator app (or passkey) mandatory for the holder.
 |
 | Permissions not yet used by a module are declared now so roles are stable as
 | later sprints add features.
 */

return [
    'permissions' => [
        // Platform
        'platform.tenants.view' => 'See all training providers',
        'platform.tenants.manage' => 'Create, approve, suspend and place training providers',
        'platform.cells.view' => 'See cells and their load',
        'platform.users.manage' => 'Manage platform staff and their roles',
        'platform.audit.view' => 'Read the audit trail of every provider',
        'platform.support.request' => 'Ask a provider for support access',
        'platform.settings.manage' => 'Company details, payment, messaging and storage settings',
        'platform.billing.manage' => 'Packages, subscriptions, invoices and payments of all providers',
        'platform.catalogue.manage' => 'Publish qualifications to the shared catalogue and review suggestions',
        'regulator.view' => 'Read compliance evidence across providers',
        'verify.records' => 'Verify certificates and statements of results',
        'marketplace.jobs.manage' => 'Post jobs and review candidates',
        'marketplace.pitches.view' => 'Browse graduate business pitches',
        'marketplace.pitches.manage' => 'Create and manage business pitches',

        // Training provider
        'tenant.settings.manage' => 'Brand, domains and provider settings',
        'tenant.billing.manage' => 'Plan, payment method and invoices',
        'tenant.users.view' => 'See people in the provider',
        'tenant.users.invite' => 'Invite people to the provider',
        'tenant.roles.assign' => 'Assign and remove roles',
        'tenant.audit.view' => 'Read the provider audit trail',
        'tenant.support.approve' => 'Approve or revoke platform support access',
        'centres.view' => 'See centres',
        'centres.manage' => 'Create and edit centres',
        'tenant.roles.manage' => 'Create and change the provider\'s own roles',
        'credentials.view' => 'See staff registrations (facilitators, assessors, moderators, mentors)',
        'credentials.manage' => 'Add and verify staff registrations',
        'people.manage' => 'Edit profiles and import people',
        'employers.view' => 'See host employers, workplace sites, approvals and mentors',
        'employers.manage' => 'Add and change host employers, sites and mentors',
        'agreements.view' => 'See agreements, their signatures and signed copies',
        'agreements.manage' => 'Write agreement templates, send agreements and follow them up',
        'programmes.view' => 'See the qualifications the provider offers',
        'programmes.manage' => 'Add qualifications from the catalogue, set centre scope, suggest catalogue changes',
        'learners.view' => 'See learner records',
        'learning.deliver' => 'Deliver learning sessions',
        'assessment.conduct' => 'Conduct assessments',
        'assessment.moderate' => 'Moderate assessments',
        'assessment.external' => 'Run external integrated summative assessments',
        'workplace.logbook.sign' => 'Sign workplace logbooks',
        'workplace.placements.manage' => 'Manage workplace placements',
        'learning.self' => 'Learn: content, simulators, mock tests',
    ],

    'roles' => [
        'super-admin' => ['label' => 'Super Admin', 'scope' => 'platform', 'mfa' => true, 'permissions' => [
            'platform.tenants.view', 'platform.tenants.manage', 'platform.cells.view', 'platform.users.manage',
            'platform.audit.view', 'platform.support.request', 'platform.settings.manage', 'platform.billing.manage',
            'platform.catalogue.manage',
        ]],
        // Also keeps the shared qualification catalogue (our team, or TYM's quality manager).
        'jv-compliance' => ['label' => 'JV compliance desk', 'scope' => 'platform', 'mfa' => true, 'permissions' => [
            'platform.tenants.view', 'platform.audit.view', 'regulator.view', 'platform.catalogue.manage',
        ]],
        'qcto-officer' => ['label' => 'QCTO officer', 'scope' => 'platform', 'mfa' => true, 'permissions' => [
            'platform.tenants.view', 'regulator.view', 'verify.records',
        ]],
        'ceta-officer' => ['label' => 'CETA officer', 'scope' => 'platform', 'mfa' => true, 'permissions' => [
            'platform.tenants.view', 'regulator.view', 'verify.records',
        ]],
        'public-verifier' => ['label' => 'Verifier', 'scope' => 'platform', 'mfa' => false, 'permissions' => ['verify.records']],
        'hiring-company' => ['label' => 'Hiring company', 'scope' => 'platform', 'mfa' => false, 'permissions' => ['marketplace.jobs.manage']],
        'investor' => ['label' => 'Investor', 'scope' => 'platform', 'mfa' => false, 'permissions' => ['marketplace.pitches.view']],
        'entrepreneur' => ['label' => 'Entrepreneur', 'scope' => 'platform', 'mfa' => false, 'permissions' => ['marketplace.pitches.manage']],

        'tp-owner' => ['label' => 'Training provider owner', 'scope' => 'tenant', 'mfa' => true, 'permissions' => [
            'tenant.settings.manage', 'tenant.users.view', 'tenant.users.invite', 'tenant.roles.assign',
            'tenant.audit.view', 'tenant.support.approve', 'centres.view', 'centres.manage', 'learners.view',
            'tenant.billing.manage', 'programmes.view', 'programmes.manage',
            'tenant.roles.manage', 'credentials.view', 'credentials.manage', 'people.manage',
            'employers.view', 'employers.manage', 'agreements.view', 'agreements.manage',
        ]],
        'host-employer' => ['label' => 'Host employer', 'scope' => 'tenant', 'mfa' => false, 'permissions' => [
            'workplace.placements.manage', 'learners.view',
        ]],
        'assessment-centre' => ['label' => 'Assessment centre', 'scope' => 'tenant', 'mfa' => false, 'permissions' => [
            'assessment.external', 'learners.view',
        ]],

        'centre-manager' => ['label' => 'Centre manager', 'scope' => 'centre', 'mfa' => false, 'permissions' => [
            'tenant.users.view', 'tenant.users.invite', 'tenant.roles.assign', 'tenant.audit.view', 'centres.view', 'learners.view',
            'programmes.view', 'credentials.view', 'credentials.manage', 'people.manage',
            'employers.view', 'employers.manage', 'agreements.view', 'agreements.manage',
        ]],
        'facilitator' => ['label' => 'Facilitator', 'scope' => 'centre', 'mfa' => false, 'permissions' => ['learners.view', 'learning.deliver', 'programmes.view']],
        'assessor' => ['label' => 'Assessor', 'scope' => 'centre', 'mfa' => false, 'permissions' => ['learners.view', 'assessment.conduct', 'programmes.view']],
        'moderator' => ['label' => 'Moderator', 'scope' => 'centre', 'mfa' => false, 'permissions' => ['learners.view', 'assessment.moderate', 'programmes.view']],
        'site-supervisor' => ['label' => 'Site supervisor', 'scope' => 'centre', 'mfa' => false, 'permissions' => ['learners.view', 'workplace.logbook.sign']],
        'learner' => ['label' => 'Learner', 'scope' => 'centre', 'mfa' => false, 'permissions' => ['learning.self']],
    ],

    /*
     | Effective permissions of platform staff inside a provider during an approved
     | support-access window. Not assignable; settings stay read-only for safety.
     */
    'support_permissions' => ['tenant.users.view', 'tenant.audit.view', 'centres.view', 'learners.view'],

    /* Roles a centre-scoped assigner may hand out (never above their own level). */
    'delegable_by_centre_manager' => ['facilitator', 'assessor', 'moderator', 'site-supervisor', 'learner'],
];
