<?php
/**
 * OAuth 2.0 Refresh token grant
 *
 * @package     league/oauth2-server
 * @author      Alex Bilbie <hello@alexbilbie.com>
 * @copyright   Copyright (c) Alex Bilbie
 * @license     http://mit-license.org/
 * @link        https://github.com/thephpleague/oauth2-server
 */

namespace League\OAuth2\Server\Grant;

use DateInterval;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\ValidationData;
use League\OAuth2\Server\Entities\AccessTokenEntity;
use League\OAuth2\Server\Entities\Interfaces\ClientEntityInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntity;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use League\OAuth2\Server\Utils\KeyCrypt;
use League\OAuth2\Server\Utils\SecureKey;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\EventDispatcher\Event;

/**
 * Refresh token grant
 */
class RefreshTokenGrant extends AbstractGrant
{
    /**
     * @var \League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface
     */
    private $refreshTokenRepository;
    /**
     * @var string
     */
    private $pathToPublicKey;

    /**
     * @param string                                                             $pathToPublicKey
     * @param \League\OAuth2\Server\Repositories\ClientRepositoryInterface       $clientRepository
     * @param \League\OAuth2\Server\Repositories\ScopeRepositoryInterface        $scopeRepository
     * @param \League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface  $accessTokenRepository
     * @param \League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface $refreshTokenRepository
     */
    public function __construct(
        $pathToPublicKey,
        ClientRepositoryInterface $clientRepository,
        ScopeRepositoryInterface $scopeRepository,
        AccessTokenRepositoryInterface $accessTokenRepository,
        RefreshTokenRepositoryInterface $refreshTokenRepository
    ) {
        $this->pathToPublicKey = $pathToPublicKey;
        $this->refreshTokenRepository = $refreshTokenRepository;
        parent::__construct($clientRepository, $scopeRepository, $accessTokenRepository);
    }

    /**
     * @inheritdoc
     */
    public function respondToRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        DateInterval $tokenTTL,
        $scopeDelimiter = ' '
    ) {
        // Get the required params
        $clientId = isset($request->getParsedBody()['client_id'])
            ? $request->getParsedBody()['client_id'] // $_POST['client_id']
            : (isset($request->getServerParams()['PHP_AUTH_USER'])
                ? $request->getServerParams()['PHP_AUTH_USER'] // $_SERVER['PHP_AUTH_USER']
                : null);

        if (is_null($clientId)) {
            throw OAuthServerException::invalidRequest('client_id', null, '`%s` parameter is missing');
        }

        $clientSecret = isset($request->getParsedBody()['client_secret'])
            ? $request->getParsedBody()['client_secret'] // $_POST['client_id']
            : (isset($request->getServerParams()['PHP_AUTH_PW'])
                ? $request->getServerParams()['PHP_AUTH_PW'] // $_SERVER['PHP_AUTH_USER']
                : null);

        if (is_null($clientSecret)) {
            throw OAuthServerException::invalidRequest('client_secret', null, '`%s` parameter is missing');
        }

        $encryptedRefreshToken = isset($request->getParsedBody()['refresh_token'])
            ? $request->getParsedBody()['refresh_token']
            : null;

        if ($encryptedRefreshToken === null) {
            throw OAuthServerException::invalidRequest('refresh_token', null, '`%s` parameter is missing');
        }

        // Validate client ID and client secret
        $client = $this->clientRepository->getClientEntity(
            $clientId,
            $clientSecret,
            null,
            $this->getIdentifier()
        );

        if (($client instanceof ClientEntityInterface) === false) {
            $this->emitter->emit(new Event('client.authentication.failed', $request));
            throw OAuthServerException::invalidClient();
        }

        // Validate refresh token
        try {
            $oldRefreshToken = KeyCrypt::decrypt($encryptedRefreshToken, $this->pathToPublicKey);
        } catch (\LogicException $e) {
            throw OAuthServerException::invalidRefreshToken('Cannot parse refresh token: ' . $e->getMessage());
        }

        $oldRefreshTokenData = json_decode($oldRefreshToken, true);
        if ($oldRefreshTokenData['client_id'] !== $client->getIdentifier()) {
            throw OAuthServerException::invalidRefreshToken('Token is not linked to client' . ' got: ' . $client->getIdentifier() . ' expected: '. $oldRefreshTokenData['client_id']);
        }

        if ($oldRefreshTokenData['expire_time'] < time()) {
            throw OAuthServerException::invalidRefreshToken('Token has expired');
        }

        if ($this->refreshTokenRepository->isRefreshTokenRevoked($oldRefreshTokenData['refresh_token_id']) === true) {
            throw OAuthServerException::invalidRefreshToken('Token has been revoked');
        }

        // Get and validate any requested scopes
        $scopeParam = isset($request->getParsedBody()['scope'])
            ? $request->getParsedBody()['scope'] // $_POST['scope']
            : '';
        $requestedScopes = $this->validateScopes($scopeParam, $scopeDelimiter, $client);

        // If no new scopes are requested then give the access token the original session scopes
        if (count($requestedScopes) === 0) {
            $newScopes = $oldRefreshTokenData['scopes'];
        } else {
            // The OAuth spec says that a refreshed access token can have the original scopes or fewer so ensure
            //  the request doesn't include any new scopes
            foreach ($requestedScopes as $requestedScope) {
                if (in_array($requestedScope->getIdentifier(), $oldRefreshTokenData['scopes']) === false) {
                    throw OAuthServerException::invalidScope($requestedScope->getIdentifier());
                }
            }

            $newScopes = $requestedScopes;
        }

        // Generate a new access token
        $accessToken = new AccessTokenEntity();
        $accessToken->setIdentifier(SecureKey::generate());
        $accessToken->setExpiryDateTime((new \DateTime())->add($tokenTTL));
        $accessToken->setClient($client);
        $accessToken->setUserIdentifier($oldRefreshTokenData['user_id']);
        foreach ($newScopes as $scope) {
            $accessToken->addScope($scope);
        }

        // Expire the old tokens and save the new one
        $this->accessTokenRepository->revokeAccessToken($oldRefreshTokenData['access_token_id']);
        $this->refreshTokenRepository->revokeRefreshToken($oldRefreshTokenData['refresh_token_id']);

        // Generate a new refresh token
        $refreshToken = new RefreshTokenEntity();
        $refreshToken->setIdentifier(SecureKey::generate());
        $refreshToken->setExpiryDateTime((new \DateTime())->add(new DateInterval('P1M')));
        $refreshToken->setAccessToken($accessToken);

        // Persist the tokens
        $this->accessTokenRepository->persistNewAccessToken($accessToken);
        $this->refreshTokenRepository->persistNewRefreshToken($refreshToken);

        // Inject tokens into response
        $responseType->setAccessToken($accessToken);
        $responseType->setRefreshToken($refreshToken);

        return $responseType;
    }

    /**
     * @inheritdoc
     */
    public function canRespondToRequest(ServerRequestInterface $request)
    {
        return (
            isset($request->getParsedBody()['grant_type'])
            && $request->getParsedBody()['grant_type'] === 'refresh_token'
        );
    }
}
