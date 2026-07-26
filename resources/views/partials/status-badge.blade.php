{{--
    Shelter status badge. Takes ONLY data ($center) and emits ONLY markup.

    It receives no route and no role, which is deliberate: a shared fragment that
    knows which role it is rendering for is how cross-role branching crept into
    the old reused views. This one cannot drift that way.

    Overcapacity is a DERIVED display state -- the shelter stays active so it can
    keep accepting and tracking evacuees. Never colour-only: the label always
    states the condition in words.
--}}
@if ($center->isActive() && $center->isOvercapacity())
    <span class="badge badge-over">Overcapacity</span>
@elseif ($center->isActive())
    <span class="badge badge-success">Active</span>
@else
    <span class="badge badge-warning">{{ ucfirst($center->status) }}</span>
@endif
