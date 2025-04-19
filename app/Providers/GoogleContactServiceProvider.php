<?php
namespace App\Providers;

use Google\Client;
use Google\Service\PeopleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Redirect;
use App\Models\Invoice;

class GoogleContactServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register()
    {
        $this->app->singleton('GoogleContact', function () {
            $client = new Client();
            $client->setAuthConfig(storage_path('client_secret.json')); // Adjusted path
            $client->addScope('https://www.googleapis.com/auth/contacts');
            $client->setRedirectUri(env('GOOGLE_REDIRECT_URI'));
            $client->setLoginHint('ashish.undhad26@gmail.com'); // Force ronakrafaliya14@gmail.com
            $client->setAccessType('offline'); // Ensure refresh token is issued
            $client->setPrompt('consent select_account');
            return new GoogleContactService($client);
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot()
    {
        //
    }
}

/**
 * GoogleContactService class to handle Google Contacts logic
 */
class GoogleContactService
{
    protected $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    // Generate Google OAuth URL
    public function getAuthUrl($state = null)
    {
        if ($state) {
            $this->client->setState($state);
        }
        return $this->client->createAuthUrl();
    }

    // Handle OAuth callback
    public function authenticate($code)
    {
        try {
            $this->client->authenticate($code);
            $accessToken = $this->client->getAccessToken();
            if (!empty($accessToken['refresh_token'])) {
                // Save token with refresh_token
                Storage::put('google_access_token.json', json_encode($accessToken));
            }

            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Authentication failed: ' . $e->getMessage()];
        }
    }

    // Sync or create a contact
    public function syncContact(Request $request)
    {
        // Load token
        if (!Storage::exists('google_access_token.json')) {
            $customerName = $request->customer_name;
            $customerNumber = $request->customer_no;

            $state = base64_encode(json_encode([
                'customer_name' => $customerName,
                'customer_no' => $customerNumber,
                'invoice_id' => $request->invoice_id
            ]));

            $redirectUrl = $this->getAuthUrl($state);
            return [
                'success' => false,
                'redirect' => $redirectUrl
            ];
        }

        $accessToken = json_decode(Storage::get('google_access_token.json'), true);
        // Check if access token exists and is valid
        if (!$accessToken) {
            return redirect()->route('google.redirect');
        }

        $this->client->setAccessToken($accessToken);

        // Refresh token if expired
        if ($this->client->isAccessTokenExpired()) {
            try {
                $refreshToken = $this->client->getRefreshToken();
                if (!$refreshToken) {
                    return ['success' => false, 'redirect' => route('google.redirect')];
                }

                $this->client->fetchAccessTokenWithRefreshToken($refreshToken);
                $newAccessToken = $this->client->getAccessToken();

                // Preserve refresh token
                if (empty($newAccessToken['refresh_token']) && isset($accessToken['refresh_token'])) {
                    $newAccessToken['refresh_token'] = $accessToken['refresh_token'];
                }

                Storage::put('google_access_token.json', json_encode($newAccessToken));
            } catch (\Exception $e) {
                Storage::delete('google_access_token.json'); // Clear invalid token
                return ['success' => false, 'redirect' => route('google.redirect'), 'error' => 'Token refresh failed'];
            }
        }

        try {
            $peopleService = new PeopleService($this->client);

            // Collect contact data from request
            $name = $request->input('customer_name', 'John Doe');
            $phoneNumber = $request->input('customer_no', '+1234567890');
            $itemModel = $request->input('item_model', '');

            // Create a new person object with proper scopes
            $person = new \Google\Service\PeopleService\Person();
            $personName = new \Google\Service\PeopleService\Name();
            $personName->setGivenName($name);
            $personName->setMiddleName($itemModel);
            $person->setNames([$personName]);

            $phone = new \Google\Service\PeopleService\PhoneNumber();
            $phone->setValue($phoneNumber);
            $person->setPhoneNumbers([$phone]);

            // Create the contact in Google Contacts
            $newContact = $peopleService->people->createContact($person, [
                'personFields' => 'names,phoneNumbers'
            ]);

            $invoice_id = $request->invoice_id;

            // update invoice table with sync_contact = 1
            $invoice = Invoice::findOrFail($invoice_id);
            if($invoice) {
                $invoice->sync_contact = 1;
                $invoice->save();
            }

            return [
                'success' => true,
                'message' => 'Contact synced successfully!',
                'contact' => $newContact->getResourceName()
            ];
        } catch (\Exception $e) {
            // Check if error is auth related
            if (strpos($e->getMessage(), 'unauthorized') !== false ||
                strpos($e->getMessage(), 'invalid_grant') !== false) {
                Storage::delete('google_access_token.json');
                return ['success' => false, 'redirect' => route('google.redirect')];
            }
            return ['success' => false, 'error' => 'Sync failed: ' . $e->getMessage()];
        }
    }
}