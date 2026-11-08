<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $data['title'] }}</title>
    <link rel="stylesheet" href="/article-document.css">
</head>
<body class="article-preview-shell">
    <main>
        <header>
            <div class="article-preview-heading">
                <h1 class="ui-section-title">{{ $data['title'] }}</h1>
                @if ($author || filled($data['published_at'] ?? null))
                    <p class="ui-meta-text article-preview-meta">
                        @if ($author)
                            <span>{{ $author['name'] }}</span>
                        @endif
                        @if ($author && filled($data['published_at'] ?? null))
                            <span aria-hidden="true">·</span>
                        @endif
                        @if (filled($data['published_at'] ?? null))
                            <span><time datetime="{{ \Illuminate\Support\Carbon::parse($data['published_at'])->toIso8601String() }}">{{ \Illuminate\Support\Carbon::parse($data['published_at'])->format('d.m.Y') }}</time></span>
                        @endif
                    </p>
                @endif
            </div>
            @if ($introduction)<p class="article-preview-introduction">{{ $introduction }}</p>@endif
            @if ($cover)<img class="article-preview-cover" src="{{ $cover }}" alt="{{ $data['cover_alt'] ?? '' }}">@endif
        </header>
        <div class="ui-prose">{!! $body !!}</div>
    </main>
</body>
</html>
