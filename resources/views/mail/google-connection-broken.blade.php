<x-mail::message>
# Google refused the service account key

{{ $error }}

GA4 and Search Console data are not updated until this is fixed. Hotels do not need to do anything.

What to check: the key file is still on the server at the path in `GOOGLE_SERVICE_ACCOUNT_JSON_PATH`, the service account is not disabled or deleted in Google Cloud, and the key was not deleted there. Then open Admin > Integrations > Google and click **Test connection**.

<x-mail::button :url="$url">
Check the Google key
</x-mail::button>
</x-mail::message>
