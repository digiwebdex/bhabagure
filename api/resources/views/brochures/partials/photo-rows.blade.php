{{-- Rows of photos from App\Services\Brochures\GroupTourPhotos::rows(): each photo at its own width for the row's height,
     so it keeps its shape. Caption under it when the Group Tour Gallery gives one. --}}
@if ($rows !== [])
  <div class="{{ $class }}">
    @foreach ($rows as $row)
      <div class="row">
        @foreach ($row['photos'] as $photo)
          <figure style="width: {{ $photo['widthMm'] }}mm">
            <img src="{{ $photo['src'] }}" alt="" style="width: {{ $photo['widthMm'] }}mm; height: {{ $row['heightMm'] }}mm">
            @if ($photo['caption'])<figcaption>{{ $photo['caption'] }}</figcaption>@endif
          </figure>
        @endforeach
      </div>
    @endforeach
  </div>
@endif
