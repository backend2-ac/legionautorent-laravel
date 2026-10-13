@if (! empty($breadcrumbs))
    <nav class="breadcrumbs container" aria-label="{{ site_text('Навигационная цепочка') }}">
        <ol>
            @foreach ($breadcrumbs as [$label, $url])
                <li>
                    @unless ($loop->first)
                        <span class="breadcrumb-separator" aria-hidden="true">›</span>
                    @endunless
                    @if ($loop->last)
                        <span aria-current="page">{{ $label }}</span>
                    @else
                        <a href="{{ $url }}">{{ $label }}</a>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
