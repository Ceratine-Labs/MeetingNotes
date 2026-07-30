@extends('core::layouts.marketing')

@section('title', 'Privacy policy: ' . config('app.name'))
@section('meta_description', 'How ' . config('app.name') . ' handles your meeting transcripts and personal information.')
@section('robots', 'index, follow')

{{--
    ⚠ NOT LEGAL ADVICE, AND NOT REVIEWED BY A LAWYER.

    Written to be accurate about what this codebase actually does — the data
    listed below is the data the schema really holds, and the sub-processor list
    matches the services the app really calls. That accuracy is the useful part;
    it is what makes this reviewable rather than boilerplate.

    Before taking real payments Ryan needs this reviewed for POPIA compliance,
    and needs to name a real Information Officer (POPIA requires one, and
    config('site.contact_email') is a placeholder address).

    Two things this policy claims that MUST stay true in code, or the policy
    becomes a false statement:
      - transcripts are not used to train models;
      - deleted accounts are erased after config('site.data_retention_days').
    The second is not yet implemented — there is no scheduled erasure job. It is
    tracked as follow-up work, and the wording says "will be" rather than
    implying an automated process that does not exist.
--}}

@section('content')
    <div class="container-xl py-6">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <h1 class="mb-1">Privacy policy</h1>
                <p class="text-secondary mb-5">
                    Effective {{ \Illuminate\Support\Carbon::parse(config('site.legal_effective_date'))->toFormattedDayDateString() }}
                </p>

                <div class="mn-minutes">
                    <div class="alert alert-info" role="alert">
                        <div class="d-flex">
                            <i class="ti ti-info-circle me-2 mt-1"></i>
                            <div>
                                <strong>The short version.</strong> We hold your meeting material so we
                                can turn it into minutes for you. We send it to our language model
                                provider to do that, and to nobody else. We do not use it to train
                                models, we do not sell it, and you can export or delete it whenever you
                                like.
                            </div>
                        </div>
                    </div>

                    <h2>1. Who is responsible</h2>
                    <p>
                        {{ config('site.company_name') }} ({{ config('site.company_country') }}) is the
                        responsible party for the personal information described here. For any privacy
                        question, or to exercise the rights in section 8, write to
                        <a href="mailto:{{ config('site.contact_email') }}">{{ config('site.contact_email') }}</a>.
                    </p>

                    <h2>2. What we collect</h2>

                    <h3>Information you give us</h3>
                    <ul>
                        <li><strong>Account details:</strong> your name, email address and a hashed
                            password. We never store your password itself.</li>
                        <li><strong>Workspace details:</strong> the workspace name and timezone, and
                            the email addresses of people you invite.</li>
                        <li><strong>Meeting material:</strong> the transcripts, notes and documents you
                            upload, and the minutes generated from them. This is the sensitive part, and
                            it is covered specifically in section 4.</li>
                    </ul>

                    <h3>Information we generate</h3>
                    <ul>
                        <li><strong>Usage records:</strong> when a set of minutes was generated, by
                            whom, which model was used and roughly how much text it processed. We need
                            this to meter your plan allowance and to understand our own costs.</li>
                        <li><strong>Sign-in records:</strong> the time and IP address of your last
                            sign-in, kept for account security and abuse investigation.</li>
                        <li><strong>Technical logs:</strong> ordinary server and error logs.</li>
                    </ul>

                    <h3>Payment information</h3>
                    <p>
                        Card payments are handled by Paystack. <strong>Your card details are entered on
                        Paystack's own page and never reach our servers.</strong> We keep a record of
                        each payment: the amount, the date, the outcome, the card brand and its last four
                        digits: enough to answer a billing question, and nothing more.
                    </p>

                    <h2>3. Why we hold it, and on what basis</h2>
                    <ul>
                        <li><strong>To provide the service:</strong> generating, storing and exporting
                            your minutes. This is the performance of our contract with you.</li>
                        <li><strong>To bill you and meter your allowance:</strong> also contractual.</li>
                        <li><strong>To keep accounts secure and prevent abuse:</strong> our legitimate
                            interest, and yours.</li>
                        <li><strong>To contact you about your account:</strong> verification emails,
                            password resets, invitations and billing notices. These are service messages,
                            not marketing.</li>
                        <li><strong>To meet legal obligations:</strong> tax and accounting records, for
                            example.</li>
                    </ul>
                    <p>
                        We do not send marketing email unless you have asked for it, and we do not build
                        advertising profiles.
                    </p>

                    <h2>4. Your meeting material specifically</h2>
                    <p>
                        Meeting transcripts are often confidential, and can contain personal information
                        about people who never signed up here. We treat them accordingly:
                    </p>
                    <ul>
                        <li>
                            Your transcripts and minutes are visible only to members of your own
                            workspace. Workspace isolation is enforced in the application itself, not
                            just in the interface.
                        </li>
                        <li>
                            <strong>Your material is not used to train any model</strong>, ours or
                            anyone else's.
                        </li>
                        <li>
                            To generate minutes we must send the relevant text to our language model
                            provider (see section 5). That is the only third party your meeting content
                            reaches.
                        </li>
                        <li>
                            You can delete a meeting, its transcript and its minutes at any time.
                        </li>
                        <li>
                            Because transcripts may contain other people's personal information, you are
                            responsible for having the right to upload them, including any recording
                            consent your jurisdiction requires.
                        </li>
                    </ul>

                    <h2>5. Who else is involved</h2>
                    <p>
                        We use a small number of service providers to run {{ config('app.name') }}. Each
                        receives only what it needs to do its job.
                    </p>
                    <ul>
                        <li>
                            <strong>Language model provider</strong> (Anthropic, or another provider
                            configured by the administrator): receives your meeting text in order to
                            generate minutes. It is not used for training.
                        </li>
                        <li>
                            <strong>Paystack:</strong> payment processing. Receives your email address
                            and payment details; receives no meeting content.
                        </li>
                        <li>
                            <strong>Hosting and email providers:</strong> the servers the application
                            runs on and the service that delivers our transactional email.
                        </li>
                    </ul>
                    <p>
                        We do not sell your personal information, and we do not share it for advertising.
                    </p>

                    <h2>6. Where it is stored</h2>
                    <p>
                        Your data is stored on our servers and backed up regularly. Some of our providers, the
                        language model provider in particular, process data outside
                        {{ config('site.company_country') }}. Where that happens we rely on the provider's
                        contractual data-protection commitments.
                    </p>

                    <h2>7. How long we keep it</h2>
                    <ul>
                        <li><strong>While your account is open:</strong> your meeting material stays
                            available until you delete it.</li>
                        <li><strong>After you delete your account:</strong> your data will be retained
                            for {{ config('site.data_retention_days') }} days in case you ask us to
                            restore it, and then permanently erased. Contact us if you need it erased
                            sooner.</li>
                        <li><strong>Payment and tax records:</strong> kept for as long as the law
                            requires, which is longer than the period above.</li>
                        <li><strong>Backups:</strong> deleted data persists in backups until those
                            backups age out on their normal rotation.</li>
                    </ul>

                    <h2>8. Your rights</h2>
                    <p>Under POPIA, and comparable law elsewhere, you may:</p>
                    <ul>
                        <li>ask what personal information we hold about you;</li>
                        <li>have inaccurate information corrected; you can edit your own profile
                            directly;</li>
                        <li>ask us to delete your information;</li>
                        <li>get a copy of your content; export is available on every plan, including
                            free, and we deliberately never restrict Markdown export;</li>
                        <li>object to particular processing;</li>
                        <li>complain to the Information Regulator of South Africa.</li>
                    </ul>
                    <p>
                        Write to
                        <a href="mailto:{{ config('site.contact_email') }}">{{ config('site.contact_email') }}</a>
                        and we will respond within the period the law allows.
                    </p>

                    <h2>9. Security</h2>
                    <p>
                        Passwords are stored hashed, never in plain text. Connections are encrypted in
                        transit. Workspace isolation is enforced in the application layer. Invitation
                        tokens are stored hashed, so a stolen database yields no working invitation
                        links. Access to production systems is limited to the people who need it.
                    </p>
                    <p>
                        No system is perfectly secure. If a breach affects your personal information we
                        will notify you and the Information Regulator as POPIA requires.
                    </p>

                    <h2>10. Cookies</h2>
                    <p>
                        We use only the cookies the application needs to work: a session cookie to keep
                        you signed in, a CSRF token to protect form submissions, and a small cookie
                        remembering whether you prefer the light or dark theme. There are no advertising
                        or third-party tracking cookies.
                    </p>

                    <h2>11. Children</h2>
                    <p>
                        The service is intended for business use and is not directed at children. We do
                        not knowingly collect information from anyone under 18.
                    </p>

                    <h2>12. Changes</h2>
                    <p>
                        If we change this policy materially we will let you know by email or in the
                        application before the change takes effect. The effective date at the top always
                        reflects the current version.
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
