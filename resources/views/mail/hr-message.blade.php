<!doctype html>
<html lang="tr">
<body style="font-family: Arial, sans-serif; font-size: 14px; color: #1f2937; line-height: 1.5">
    <p>
        @foreach (preg_split('/\R/u', $body) as $line)
            {{ $line }}@if (! $loop->last)<br>@endif
        @endforeach
    </p>
    <p style="color: #6b7280">{{ $company }}</p>
</body>
</html>
