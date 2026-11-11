@props(['status'])
<span class="badge {{ match($status) {'completed'=>'bg-green-lt','running','processing'=>'bg-blue-lt','failed','interrupted'=>'bg-red-lt','cancelled','draft'=>'bg-secondary-lt',default=>'bg-yellow-lt'} }}">{{ ucfirst(str_replace('_', ' ', $status)) }}</span>
