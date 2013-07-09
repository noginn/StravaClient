<?php

namespace Endurance\Strava;

use Buzz\Browser;
use Buzz\Message\Form\FormRequest;
use Buzz\Message\Form\FormUpload;
use Buzz\Message\Request;
use Buzz\Message\Response;
use Buzz\Util\Url;
use Symfony\Component\CssSelector\CssSelector;
use Symfony\Component\DomCrawler\Crawler;

class StravaClient
{
    protected $browser;
    protected $authenticityToken;

    public function __construct(Browser $browser)
    {
        $this->browser = $browser;

        // Set client options
        $client = $this->browser->getClient();

        // Don't follow redirects (important for asserting we signed in)
        $client->setOption(CURLOPT_FOLLOWLOCATION, false);

        // Initialise the cookie jar
        $cookieFile = tempnam('/tmp', 'StravaClient');
        $client->setOption(CURLOPT_COOKIEJAR, $cookieFile);
        $client->setOption(CURLOPT_COOKIEFILE, $cookieFile);

        // Remove the timeout to allow time to download large files
        $client->setTimeout(0);
    }

    public function signIn($username, $password)
    {
        // Load the login page to get the authenticity token
        $response = $this->browser->get('https://www.strava.com/login');
        $crawler = new Crawler($response->getContent());
        $authenticityToken = $crawler->filterXPath(CssSelector::toXPath('input[name=authenticity_token]'))->attr('value');

        // Post the login form
        $response = $this->browser->post('https://www.strava.com/session', array(), http_build_query(array(
            'authenticity_token' => $authenticityToken,
            'email' => $username,
            'password' => $password
        )));

        // Get the new authenticity token
        $response = $this->browser->get('http://app.strava.com/upload/select');
        $crawler = new Crawler($response->getContent());
        $authenticityToken = $crawler->filterXPath(CssSelector::toXPath('meta[name=csrf-token]'))->attr('content');

        $this->authenticityToken = $authenticityToken;
    }

    public function isSignedIn()
    {
        return $this->authenticityToken !== null;
    }

    public function uploadActivity($file)
    {
        if (!$this->isSignedIn()) {
            throw new \RuntimeException('Not signed in');
        }

        $request = new Request(Request::METHOD_POST);

        // Set the request URL
        $url = new Url('http://app.strava.com/upload/files');
        $url->applyToRequest($request);

        // Manually build POST data due to a bug in Buzz
        // that means the file upload field name cannot contain "[]"
        $boundary = sha1(rand(11111, 99999) . time() . uniqid());

        $content = '';
        $content .= '--' . $boundary . "\r\n";
        $content .= "Content-Disposition: form-data; name=\"_method\"\r\n";
        $content .= "\r\n";
        $content .= "post\r\n";
        $content .= '--' . $boundary . "\r\n";
        $content .= "Content-Disposition: form-data; name=\"new_uploader\"\r\n";
        $content .= "\r\n";
        $content .= "1\r\n";
        $content .= '--' . $boundary . "\r\n";
        $content .= "Content-Disposition: form-data; name=\"authenticity_token\"\r\n";
        $content .= "\r\n";
        $content .= $this->authenticityToken . "\r\n";
        $content .= '--' . $boundary . "\r\n";
        $content .= "Content-Disposition: form-data; name=\"files[]\"; filename=\"" . basename($file) . "\"\r\n";
        $content .= "Content-Type: application/octet-stream\r\n";
        $content .= "\r\n";
        $content .= file_get_contents($file) . "\r\n";
        $content .= '--' . $boundary . '--';

        $request->setContent($content);

        // Set required headers
        $request->setHeaders(array(
            'X-CSRF-Token:' . $this->authenticityToken,
            'X-Requested-With:XMLHttpRequest',
            // Buzz attempts to remove this header.
            // It only works due to a bug in Buzz where the header is only stripped if there is a space after the ":".
            'Content-Type:multipart/form-data; boundary='. $boundary
        ));


        $response = new Response();
        $this->browser->getClient()->send($request, $response);

        return json_decode($response->getContent(), true);
    }
}
