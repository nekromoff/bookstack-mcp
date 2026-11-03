@extends('layouts.simple')

@section('body')
    <div class="container very-small pt-xl">

        <div class="card content-wrap auto-height">
            <h1 class="list-heading">Can't connect this app</h1>
            <p>{{ $message }}</p>
            <div class="text-right">
                <a href="{{ url('/') }}" class="button outline">Back to {{ setting('app-name') }}</a>
            </div>
        </div>

    </div>
@stop
