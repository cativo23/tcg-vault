{{-- Shown directly above every signup button, so accepting the terms is
     part of creating the account rather than a footer link. --}}
<p class="mt-4 text-xs" style="color: var(--muted)">
    By creating an account you agree to the
    <a href="{{ route('terms') }}" class="underline" style="color: var(--ink)">Terms of use</a>
    and confirm you’ve read the
    <a href="{{ route('privacy') }}" class="underline" style="color: var(--ink)">Privacy policy</a>,
    and that you’re at least 13 (with a parent’s or guardian’s permission if you’re under 18).
</p>
