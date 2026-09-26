@component('layouts.public', ['title' => 'Privacy policy', 'description' => 'What tcg-vault collects, why, where it lives, and how to get it out or delete it.'])
<article class="nw-wrap nw-legal py-10">
    <p class="nw-eyebrow">tcg-vault · legal</p>
    <h1 class="nw-display nw-h1 nw-h1--sm">Privacy policy</h1>
    <p class="nw-legal-meta">Last updated September 2026</p>

    <p>tcg-vault is a small, independent Pokémon TCG collection tracker run by one person, Carlos Cativo (“I”), from El Salvador. I am the controller of the data described here. This page explains what the app stores about you, why, and what you can do about it. It is written to meet the EU General Data Protection Regulation (GDPR) as well as Salvadoran law, since anyone can visit the public galleries.</p>

    <h2>What I collect</h2>
    <ul>
        <li><strong>Your account:</strong> name, username, email address, and your password (stored only as a one-way hash, never readable). Name, email and password are needed to have an account.</li>
        <li><strong>Your collection:</strong> the cards you add and everything you enter about them — variant, condition, grade, quantity, notes and photos. Location and other details your phone embeds in a photo (such as the device and when it was taken) are removed before the photo is stored; only its rotation and colour profile are kept.</li>
        <li><strong>Invites:</strong> the email address an invite was created for, and who created, accepted or revoked it. The app does not email invites; they are shared as links.</li>
        <li><strong>Feedback you send:</strong> your message, its type, your username and email, and the page you were on.</li>
        <li><strong>Cookies:</strong> a session cookie and a security (CSRF) cookie, plus — only if you tick “Remember me” — a login cookie that lasts up to about 400 days; and, only if the age check at signup fails, a cookie that blocks another attempt (see “Children”). All are strictly necessary for the app to work, so no consent is asked. Your light/dark theme choice is saved in your own browser only.</li>
        <li><strong>Error and debug records:</strong> see “Where your data lives” below.</li>
    </ul>
    <p>There is no advertising, no analytics and no tracking across other sites. No automated decisions are made about you.</p>

    <h2>Why, and on what legal basis</h2>
    <ul>
        <li>To run your account and show your collection — <em>performance of the service you signed up for</em>.</li>
        <li>To keep the app secure and fix errors — <em>legitimate interest</em> in a working, safe service.</li>
        <li>To read and answer your feedback — <em>legitimate interest</em>, and your choice to send it.</li>
    </ul>

    <h2>Children</h2>
    <p>At signup you enter your birth month and year. It is only used to check your age at that moment and is not stored. If the check fails, a cookie means you can’t sign up from that browser for a year. You must be at least 13 to create an account, and have a parent’s or guardian’s permission if you’re under 18 (confirmed with a checkbox at signup). If I learn that an account belongs to someone younger than 13, or to a minor without that permission, I’ll delete it. A parent or guardian can ask me to at <a href="mailto:admin@cativo.dev">admin@cativo.dev</a>.</p>

    <h2>Public and private</h2>
    <p>Every account with a username has a page at <code>/your-username</code>. Your collection starts <strong>private</strong>: the page then shows only your username and name, and no cards. If you switch it to <strong>public</strong> (on My Collection), anyone can see your cards, their values and your activity there. You can switch back to private at any time.</p>
    <p>Card photos are stored at long, unguessable web addresses. They are only linked from your page while your collection is public, but anyone who already has a photo’s exact address could still open it.</p>

    <h2>Where your data lives, and who else touches it</h2>
    <ul>
        <li><strong>Hosting:</strong> the app, its database and your photos run on a server rented from Hetzner Online GmbH in Nuremberg, Germany (EU).</li>
        <li><strong>Email:</strong> feedback and account emails (such as password resets) are sent through Resend, Inc., in the United States.</li>
        <li><strong>Error reports:</strong> when something breaks, a report goes to a self-hosted Bugsink instance on my own server infrastructure. It includes the request that failed, which can contain other things you typed.</li>
        <li><strong>Debug records:</strong> a short-lived log of failed requests and errors, which can include your IP address, account name and email, and the request details. It is deleted within 3 days. Passwords you type are masked before either record is kept.</li>
        <li><strong>Card data and artwork:</strong> card images load directly from tcgdex (<code>assets.tcgdex.net</code>), so your browser contacts their servers and they see your IP address.</li>
    </ul>
    <p>Where data goes to the United States (Resend), the transfer relies on the EU–US Data Privacy Framework or the provider’s Standard Contractual Clauses. I don’t sell or share your data with anyone else.</p>

    <h2>How long I keep it</h2>
    <ul>
        <li>Your account and collection: until you delete them.</li>
        <li>A login session: up to a year unless you log out; the “Remember me” cookie up to about 400 days.</li>
        <li>Debug records: deleted within 3 days. Error reports: kept only as long as needed to fix the problem.</li>
        <li>Feedback emails: kept in my inbox for as long as needed to follow up.</li>
        <li>An invite nobody accepted: deleted within 31 days of expiring or being revoked. An accepted invite is deleted with the account it created.</li>
        <li>Password reset links: expire after an hour and are deleted within a day.</li>
        <li>If staff suspend or delete an account: a record of that, holding the account’s internal number but not its name or email, kept for a year.</li>
    </ul>

    <h2>Your rights</h2>
    <p>You can, at any time:</p>
    <ul>
        <li><strong>Get a copy</strong> of your collection — “Export CSV” on My Collection downloads every card with all its details (photos are not included).</li>
        <li><strong>Correct</strong> your details on your Profile, and your cards on My Collection.</li>
        <li><strong>Delete your account</strong> from your Profile. This immediately and permanently removes your account, your whole collection with its notes and photos, and the invite you signed up with. Copies in short-lived debug records and error reports expire on the schedule above.</li>
        <li><strong>Ask</strong> for access to what I hold about you, or for restriction or portability of it; object to how it’s used; or withdraw a choice such as making your collection public.</li>
        <li><strong>Complain</strong> to your local data protection authority.</li>
    </ul>
    <p>I reply to requests within one month. If a breach puts your data at risk, I’ll tell you without undue delay.</p>

    <h2>Contact</h2>
    <p>Write to <a href="mailto:admin@cativo.dev">admin@cativo.dev</a>, or use “Feedback” in the app’s top bar while logged in.</p>

    <h2>Changes</h2>
    <p>If this policy changes in a way that matters, I’ll update the date above and email members.</p>
</article>
@endcomponent
