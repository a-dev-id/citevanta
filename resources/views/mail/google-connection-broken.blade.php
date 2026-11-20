<x-mail::message>
# Google needs to be reconnected

{{ $error }}

GA4 and Search Console data are not updated until the agent account is connected again. Hotels do not need to do anything.

While the Google Cloud app is in "Testing" mode, Google ends the connection after 7 days. Publishing the app (OAuth consent screen > Publish app) avoids this.

<x-mail::button :url="$url">
Reconnect Google
</x-mail::button>
</x-mail::message>
