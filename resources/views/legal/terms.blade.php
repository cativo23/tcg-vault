@component('layouts.public', ['title' => 'Terms of use', 'description' => 'The rules for using tcg-vault during its private beta.'])
<article class="nw-wrap nw-legal py-10">
    <p class="nw-eyebrow">tcg-vault · legal</p>
    <h1 class="nw-display nw-h1 nw-h1--sm">Terms of use</h1>
    <p class="nw-legal-meta">Last updated September 2026</p>

    <p>tcg-vault is a small, independent collection tracker run by one person, Carlos Cativo (“I”). By creating an account you agree to these terms. How your data is handled is covered separately in the <a href="{{ route('privacy') }}">privacy policy</a>.</p>

    <h2>A private beta</h2>
    <p>The app is currently in an invite-only beta. It is free, it will change, and features may be added, altered or removed. I may also pause or end the service; if I do, I’ll email members at least 30 days before so you can download your collection (CSV export; photos are not included).</p>

    <h2>Your account</h2>
    <ul>
        <li>Keep your password to yourself; you are responsible for what happens under your account.</li>
        <li>Pick a username that doesn’t impersonate anyone or break the rules below — it becomes a public web address.</li>
        <li>You can delete your account at any time from your Profile.</li>
    </ul>

    <h2>Your content</h2>
    <p>The notes and photos you add stay yours. You let me store, back up, process and display them only to run the service: on your pages, and publicly only while your collection is public. This permission ends when you delete the content or your account, apart from copies that remain briefly in routine logs.</p>
    <p>Only upload photos you took or have the right to use, and nothing illegal, hateful or unrelated to your collection. Photos can be JPEG, PNG or WebP, up to 5&nbsp;MB, and are stored at unguessable web addresses — anyone who already has a photo’s exact address can still open it. I don’t review content in advance; report anything wrong through Feedback or <a href="mailto:admin@cativo.dev">admin@cativo.dev</a>.</p>

    <h2>Prices are references, not appraisals</h2>
    <p>Card data, artwork and market prices come from tcgdex, which aggregates TCGplayer and Cardmarket, and are refreshed on a daily schedule. They can be wrong, late or missing. Don’t rely on them alone to buy, sell, insure or trade.</p>

    <h2>Acceptable use</h2>
    <p>Don’t try to break, overload or scrape the service, access other people’s accounts, or use it to send spam or abuse. I may suspend or delete accounts that break these rules. Except for serious or illegal abuse, I’ll email you first, explain why, and give you a chance to respond and export your data.</p>

    <h2>Ownership and card images</h2>
    <p>The app’s code, design and name belong to me. Pokémon and all related names and artwork belong to Nintendo, Creatures, GAME FREAK and The Pokémon Company; tcg-vault is not affiliated with or endorsed by any of them. Card images are shown only to identify cards; rights holders can contact me to have them removed.</p>

    <h2>No guarantees, and limits on liability</h2>
    <p>The service is provided free and “as is”, without guarantees that it will be available, error-free or keep your data. There are currently no off-site backups, so keep your own copy (the CSV export) of anything that matters to you.</p>
    <p>Nothing in these terms limits liability for death or personal injury caused by negligence, for intent or gross negligence, or any liability that cannot be limited by law, including your statutory rights as a consumer. Otherwise, as far as the law allows, I’m not liable for lost data, lost profits, or decisions based on the prices shown.</p>

    <h2>Governing law and disputes</h2>
    <p>These terms are governed by the laws of El Salvador, and the courts of San Salvador have jurisdiction. If you are a consumer in the EU, you keep the protection of the mandatory laws of the country where you live and may also bring proceedings in its courts. Please contact me first at <a href="mailto:admin@cativo.dev">admin@cativo.dev</a> — most problems can be solved that way.</p>

    <h2>Changes</h2>
    <p>If I change these terms in a way that matters, I’ll update the date above and email members at least 30 days before the change takes effect. If you don’t agree, you can export your data and delete your account before then.</p>

    <h2>The rest</h2>
    <p>If any part of these terms is unenforceable, the rest still applies. These terms and the privacy policy are the whole agreement between us about the service.</p>
</article>
@endcomponent
