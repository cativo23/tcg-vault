@component('layouts.public', ['title' => 'Privacy policy', 'description' => 'What tcg-vault collects, why, where it lives, and how to get it out or delete it.'])
<article class="nw-wrap nw-legal py-10">
    <p class="nw-eyebrow">tcg-vault · legal</p>
    <h1 class="nw-display nw-h1 nw-h1--sm">Privacy policy</h1>
    <p class="nw-legal-meta">Last updated September 2026</p>

    <p>tcg-vault is a small, independent Pokémon TCG collection tracker run by one person, Carlos Cativo (“I”), from El Salvador. This page explains what the app stores about you, why, and what you can do about it. It is written to meet the EU General Data Protection Regulation (GDPR) as well as Salvadoran law, since anyone can visit the public galleries.</p>

    <h2>What I collect</h2>
    <ul>
        <li><strong>Your account:</strong> name, username, email address, and your password (stored only as a one-way hash, never readable).</li>
        <li><strong>Your collection:</strong> the cards you add and everything you enter about them — variant, condition, grade, quantity, notes and photos.</li>
        <li><strong>Invites:</strong> the email address an invite was sent to, and who created, accepted or revoked it.</li>
        <li><strong>Feedback you send:</strong> your message, its type, your username and email, and the page you were on.</li>
        <li><strong>Technical data:</strong> a session cookie and a security (CSRF) cookie that keep you logged in and protect forms; your light/dark theme choice, saved in your own browser; and error reports when something breaks.</li>
    </ul>
    <p>There is no advertising, no analytics and no tracking across other sites.</p>

    <h2>Why, and on what legal basis</h2>
    <ul>
        <li>To run your account and show your collection — <em>performance of the service you signed up for</em>.</li>
        <li>To keep the app secure and fix errors — <em>legitimate interest</em> in a working, safe service.</li>
        <li>To read and answer your feedback — <em>legitimate interest</em>, and your choice to send it.</li>
    </ul>

    <h2>Public and private</h2>
    <p>Every account with a username has a page at <code>/your-username</code>. Your collection starts <strong>private</strong>: the page then shows nothing. If you switch it to <strong>public</strong> (on My Collection), anyone can see your cards, their values and your activity there.</p>
    <p>Card photos are stored at long, unguessable web addresses. They are only linked from your page while your collection is public, but anyone who already has a photo’s exact address could still open it.</p>

    <h2>Where your data lives, and who else touches it</h2>
    <ul>
        <li><strong>Hosting:</strong> the app, its database and your photos run on a server rented from Hetzner Online GmbH in Nuremberg, Germany (EU).</li>
        <li><strong>Email:</strong> feedback and account emails (such as password resets) are sent through Resend, a US email provider.</li>
        <li><strong>Error tracking:</strong> error reports go to a self-hosted Bugsink instance on the same server infrastructure; they are configured not to include personal data by default. Detailed request logs for debugging are deleted automatically after 48 hours.</li>
        <li><strong>Card data and artwork:</strong> card images load directly from tcgdex (<code>assets.tcgdex.net</code>), so your browser contacts their servers and they see your IP address.</li>
        <li><strong>Fonts:</strong> the app’s typefaces load from Google Fonts, so your browser also contacts Google’s servers, which see your IP address.</li>
    </ul>
    <p>I don’t sell or share your data with anyone else.</p>

    <h2>How long I keep it</h2>
    <p>Your account and collection are kept until you delete them. A login session lasts up to a year unless you log out. Feedback emails stay in my inbox so I can follow up on them.</p>

    <h2>Your rights</h2>
    <p>You can, at any time:</p>
    <ul>
        <li><strong>Get a copy</strong> of your collection — “Export CSV” on My Collection downloads every card with all its details.</li>
        <li><strong>Correct</strong> your details on your Profile, and your cards on My Collection.</li>
        <li><strong>Delete your account</strong> from your Profile. This immediately and permanently removes your account, your whole collection with its notes and photos, and the invite you signed up with.</li>
        <li><strong>Ask</strong> what I hold about you, object to how it’s used, or complain to your local data protection authority.</li>
    </ul>

    <h2>Contact</h2>
    <p>Use “Feedback” in the app’s top bar while logged in, or write to <strong>[contact email — to be confirmed]</strong>.</p>

    <h2>Changes</h2>
    <p>If this policy changes in a way that matters, the date above changes and logged-in members are told in the app.</p>
</article>
@endcomponent
