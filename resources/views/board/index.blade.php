@extends('layouts.app')

@section('title', __('ui.board.title'))

@section('content')
    @include('board.toolbar')

    {{-- The drawer re-fetches this region after every save (X-Live-Region: board),
         so a status change moves the card/row and updates the counters at once —
         still rendered by Blade, never by a second renderer in JavaScript. --}}
    <div data-live="board">
        @fragment('board')
            @if ($lens === 'board')
                @include('board.kanban')
            @elseif ($lens === 'list')
                @include('board.list')
            @else
                @include('board.backlog')
            @endif
        @endfragment
    </div>

    @include('issues.create-modal')
@endsection

@push('scripts')
<script>
/* Board interactions: drag & drop between columns, optimistic move, drawer opening.
   Deliberately plain DOM + fetch instead of a framework: the board is the hot path
   and this keeps the payload at zero bytes of runtime beyond Alpine. */
function board() {
    return {
        dragging: null,
        over: null,

        dragStart(event, el) {
            this.dragging = {
                id: el.dataset.issueId,
                key: el.dataset.issueKey,
                el,
            };
            el.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', el.dataset.issueId);
        },

        dragEnd(event, el) {
            el.classList.remove('is-dragging');
            document.querySelectorAll('.column__body.is-over').forEach(n => n.classList.remove('is-over'));
            this.dragging = null;
            this.over = null;
        },

        dragOver(event, columnEl) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            if (this.over !== columnEl) {
                document.querySelectorAll('.column__body.is-over').forEach(n => n.classList.remove('is-over'));
                columnEl.classList.add('is-over');
                this.over = columnEl;
            }
        },

        async drop(event, columnEl) {
            event.preventDefault();
            columnEl.classList.remove('is-over');
            if (!this.dragging) return;

            const status = columnEl.dataset.status;
            const { id, key, el } = this.dragging;

            // Optimistic: move the node immediately, reconcile from the response.
            const target = document.createElement('div');
            el.replaceWith(target);
            this.insertByPointerPosition(columnEl, el, event.clientY);
            el.classList.add('is-leaving');

            this.updateColumnCount(columnEl);

            try {
                const res = await fetch(`{{ url('issues') }}/${id}/move`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ status_key: status }),
                });
                if (!res.ok) throw new Error('move failed');
                const data = await res.json();
                el.dataset.status = data.issue.status_key;
                el.classList.remove('is-leaving');
                if (data.issue.status_key === 'done') el.classList.add('card--done');
                else el.classList.remove('card--done');
                this.$dispatch('toast', {
                    message: `{{ __('ui.board.moved', ['key' => '__KEY__', 'status' => '__STATUS__']) }}`
                        .replace('__KEY__', key).replace('__STATUS__', data.status_label),
                });
            } catch (e) {
                // Put it back where it came from.
                window.location.reload();
            } finally {
                target.replaceWith(el);
                this.dragging = null;
            }
        },

        insertByPointerPosition(container, el, clientY) {
            const cards = [...container.querySelectorAll('.card:not(.is-dragging)')];
            const next = cards.find(card => {
                const box = card.getBoundingClientRect();
                return clientY < box.top + box.height / 2;
            });
            if (next) container.insertBefore(el, next);
            else container.appendChild(el);
        },

        updateColumnCount(columnEl) {
            const column = columnEl.closest('.column');
            if (!column) return;
            const count = columnEl.querySelectorAll('.card').length;
            const badge = column.querySelector('.column__count');
            if (badge) badge.textContent = count;
        },
    };
}
</script>
@endpush
