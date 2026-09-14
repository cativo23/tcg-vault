<button {{ $attributes->merge(['type' => 'submit', 'class' => 'nw-btn-danger text-sm']) }}>
    {{ $slot }}
</button>
