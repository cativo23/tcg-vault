<x-error-page
    code="403"
    :title="__('Access denied')"
    :message="$exception->getMessage() ?: __('You don\'t have permission to view this page.')"
/>
