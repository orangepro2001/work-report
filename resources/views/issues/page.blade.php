@extends('layouts.app')

@section('title', $issue->issue_key)

@section('content')
    {{-- The same panel the drawer and the modal use, on a page of its own: /issues/{id}
         opens directly, can be bookmarked, and is what "전체 화면" navigates to. --}}
    <div class="panel-page">
        {!! $panel !!}
    </div>
@endsection
