<?php

use Amp\DeferredFuture;
use FFI\CData;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LogLevel;
use Revolt\EventLoop;

require_once __DIR__ . "/vendor/autoload.php";

const APPLICATION_ID = 504853468230451221;

enum LoggingSeverity: int {
    case Verbose = 1;
    case Info = 2;
    case Warning = 3;
    case Error = 4;
    case None = 5;

    public function getPsrLevel(): string
    {
        return match($this) {
            self::Error => LogLevel::ERROR,
            self::Warning => LogLevel::WARNING,
            self::Info => LogLevel::INFO,
            self::Verbose => LogLevel::DEBUG,
            self::None => LogLevel::EMERGENCY,
        };
    }
}

enum ClientError: int {
    case None = 0;
    case ConnectionFailed = 1;
    case UnexpectedClose = 2;
    case ConnectionCancelled = 3;
}

enum ClientStatus: int {
    case Disconnected = 0;
    case Connecting = 1;
    case Connected = 2;
    case Ready = 3;
    case Reconnecting = 4;
    case Disconnecting = 5;
    case HttpWait = 6;
}
enum AuthorizationTokenType: int {
    case User = 0;
    case Bearer = 1;
}

$ffi = FFI::cdef(file_get_contents(__DIR__ . "/cdiscord.h"), match(PHP_OS_FAMILY) {
    "Windows" => "C:\\Users\\iggyvolz\\Downloads\\DiscordSocialSdk-1.0.7692\\discord_social_sdk\\bin\\release\\discord_partner_sdk.dll",
    "Linux" => "/mnt/c/Users/iggyvolz/Downloads/DiscordSocialSdk-1.0.7692/discord_social_sdk/lib/debug/libdiscord_partner_sdk.so",
});
$logger = new Logger("test");
$logger->setTimezone(new DateTimeZone("America/New_York"));
$logger->pushHandler(new StreamHandler("php://stdout"));

$logger->info("🚀 Initializing Discord SDK...\n");

$client = $ffi->new("Discord_Client");

$ffi->Discord_Client_Init(FFI::addr($client));
function strFromDiscord(CData $discord): string {
    return FFI::string($discord->ptr, $discord->size);
}
function discordString(string $str): CData {
    global $ffi;
    $discord = $ffi->new("Discord_String", owned: false);
    $discord->ptr = $charPtr = $ffi->new("uint8_t[".strlen($str)."]", owned: false);
    $discord->size  = $size = strlen($str);
    FFI::memcpy($charPtr, $str, $size);
    return $discord;
}
function logCallback(CData $message, int $severity, ?CData $userData): void
{
    global $logger;
    $logger->log(LoggingSeverity::from($severity)->getPsrLevel(), strFromDiscord($message));
}

$ffi->Discord_Client_AddLogCallback(FFI::addr($client), logCallback(...), null, null, LoggingSeverity::Verbose->value);

function statusChangedCallback(int $status, int $error, int $errorDetail, ?CData $userData){
    global $client;
    global $ffi;
    global $logger;
    $status = ClientStatus::tryFrom($status);
    $error = ClientError::tryFrom($error);
    if(is_null($status) || is_null($error)) {return;}
    $logger->info("🔄 Status changed: $status->name");
    if($status === ClientStatus::Ready) {
        $logger->info("✅ Client is ready! You can now call SDK functions.");
    } else {
        $logger->error("❌ Connection Error: $error->name - Details: $errorDetail");
        return;
    }
    $logger->info("Status changed to $status->name");
    $relationships = $ffi->new("Discord_RelationshipHandleSpan", owned: false);
    $ffi->Discord_Client_GetRelationships(FFI::addr($client), FFI::addr($relationships));
    $logger->info("👥 Friends Count: $relationships->size");
    $activity = $ffi->new("Discord_Activity", owned: false);
    $ffi->Discord_Activity_Init(FFI::addr($activity));
    $ffi->Discord_Activity_SetState(FFI::addr($activity), FFI::addr(discordString("In FFI Hell")));
    $ffi->Discord_Activity_SetDetails(FFI::addr($activity), FFI::addr(discordString("Testing FFI")));
    $future = new DeferredFuture();
    $ffi->Discord_Client_UpdateRichPresence(FFI::addr($client), FFI::addr($activity), function(CData $result, ?CData $userData) use
    (
        $future,
        $ffi
    ) {

        $clientResult = $ffi->new("Discord_ClientResult", owned: false);
        $ffi->Discord_ClientResult_Clone(FFI::addr($clientResult), $result);
        $future->complete(new Wrapper($clientResult));
    }, function(?CData $_){}, null);
    $result = $future->getFuture()->await();
    if($ffi->Discord_ClientResult_Successful(FFI::addr($result->data))) {
        $logger->info("🎮 Rich Presence updated successfully!");
    } else {
        $errorStr = $ffi->new("Discord_String", owned: false);
        $ffi->Discord_ClientResult_Error(FFI::addr($result->data), FFI::addr($errorStr));
        $logger->error("❌ Rich Presence update failed: " . strFromDiscord($errorStr));
    }
    $ffi->Discord_ClientResult_Drop(FFI::addr($result->data));
}

class Wrapper {
    public function __construct(public CData $data) {}
}

$ffi->Discord_Client_SetStatusChangedCallback(FFI::addr($client), statusChangedCallback(...), function(?CData $_){}, null);

EventLoop::defer(function() use ($ffi, $client, $logger) {
    // Generate OAuth2 code verifier for authentication
    $codeVerifier = $ffi->new("Discord_AuthorizationCodeVerifier", owned: false);
    $ffi->Discord_Client_CreateAuthorizationCodeVerifier(FFI::addr($client), FFI::addr($codeVerifier));

    // Set up authentication arguments
    $authorizationArgs = $ffi->new("Discord_AuthorizationArgs", owned: false);
    $ffi->Discord_AuthorizationArgs_Init(FFI::addr($authorizationArgs));
    $ffi->Discord_AuthorizationArgs_SetClientId(FFI::addr($authorizationArgs), APPLICATION_ID);
    $defaultPresenceScopes =  $ffi->new("Discord_String", owned: false);
    $ffi->Discord_Client_GetDefaultPresenceScopes(FFI::addr($defaultPresenceScopes));
    $logger->info("Requesting scopes " . json_encode(strFromDiscord($defaultPresenceScopes)));
    $ffi->Discord_AuthorizationArgs_SetScopes(FFI::addr($authorizationArgs), $defaultPresenceScopes);
    $challenge = $ffi->new("Discord_AuthorizationCodeChallenge", owned: false);
    $ffi->Discord_AuthorizationCodeVerifier_Challenge(FFI::addr($codeVerifier), FFI::addr($challenge));
    $ffi->Discord_AuthorizationArgs_SetCodeChallenge(FFI::addr($authorizationArgs), FFI::addr($challenge));
    $future = new DeferredFuture();

    // Begin authentication process
    $ffi->Discord_Client_Authorize(FFI::addr($client), FFI::addr($authorizationArgs), function(CData $result, CData $code, CData $redirectUri)use($ffi, $future){
        $clientResult = $ffi->new("Discord_ClientResult", owned: false);
        $ffi->Discord_ClientResult_Clone(FFI::addr($clientResult), $result);
        $future->complete([new Wrapper($clientResult), strFromDiscord($code), strFromDiscord($redirectUri)]);
    }, function(?CData $ptr){}, null);
    [$result, $code, $redirectUri] = $future->getFuture()->await();
    if(!$ffi->Discord_ClientResult_Successful(FFI::addr($result->data))) {
        $errorStr = $ffi->new("Discord_String", owned: false);
        $ffi->Discord_ClientResult_Error(FFI::addr($result->data), FFI::addr($errorStr));
        $logger->error("❌ Authentication Error: " . strFromDiscord($errorStr));
        die();
    }
    $logger->info("✅ Authorization successful! Getting access token...");

    // Exchange auth code for access token
    $codeVerifierStr = $ffi->new("Discord_String", owned: false);
    $ffi->Discord_AuthorizationCodeVerifier_Verifier(FFI::addr($codeVerifier), FFI::addr($codeVerifierStr));
    $future = new DeferredFuture();
    $ffi->Discord_Client_GetToken(
        FFI::addr($client),
        APPLICATION_ID,
        discordString($code),
        $codeVerifierStr,
        discordString($redirectUri),
        function(CData $result, CData $accessToken, CData $refreshToken, int $tokenType, int $expiresIn, CData $scopes, ?CData $userData) use (
            $future,
            $ffi) {
            $clientResult = $ffi->new("Discord_ClientResult", owned: false);
            $ffi->Discord_ClientResult_Clone(FFI::addr($clientResult), $result);
            $future->complete([new Wrapper($clientResult), strFromDiscord($accessToken), strFromDiscord($refreshToken), AuthorizationTokenType::from($tokenType), $expiresIn, strFromDiscord($scopes)]);
        },
        function(?CData $ptr){},
        null);
    [$result, $accessToken, $refreshToken, $tokenType, $expiresIn, $scopes] = $future->getFuture()->await();
    if(!$ffi->Discord_ClientResult_Successful(FFI::addr($result->data))) {
        $errorStr = $ffi->new("Discord_String", owned: false);
        $ffi->Discord_ClientResult_Error(FFI::addr($result->data), FFI::addr($errorStr));
        $logger->error("❌ Access Token Error: " . strFromDiscord($errorStr));
        die();
    }
    $logger->info("🔓 Access token received! Establishing connection...");

    $future = new DeferredFuture();
    $ffi->Discord_Client_UpdateToken(FFI::addr($client), $tokenType->value, discordString($accessToken), function(
        CData $result,
        ?CData $userData
    ) use($ffi, $future){
        $clientResult = $ffi->new("Discord_ClientResult", owned: false);
        $ffi->Discord_ClientResult_Clone(FFI::addr($clientResult), $result);
        $future->complete(new Wrapper($clientResult));
    }, function(?CData $_){}, null);
    $result = $future->getFuture()->await();
    if(!$ffi->Discord_ClientResult_Successful(FFI::addr($result->data))) {
        $errorStr = $ffi->new("Discord_String", owned: false);
        $ffi->Discord_ClientResult_Error(FFI::addr($result->data), FFI::addr($errorStr));
        $logger->error("❌ Connect Error: " . strFromDiscord($errorStr));
        die();
    }
    $logger->info("🔑 Token updated, connecting to Discord...");
    $ffi->Discord_Client_Connect(FFI::addr($client));
});
EventLoop::repeat(0.01, function()use($ffi){
    $ffi->Discord_RunCallbacks();
});

EventLoop::run();
