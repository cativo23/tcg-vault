<button {{ $attributes->merge(['type' => 'submit', 'class' => 'nw-btn-primary text-sm']) }}>
    {{ $slot }}
</button>
