<?php

/*
 * This file is part of the Nelmio SecurityBundle.
 *
 * (c) Nelmio <hello@nelm.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Nelmio\SecurityBundle\Tests\Listener;

use Nelmio\SecurityBundle\ContentSecurityPolicy\DirectiveSet;
use Nelmio\SecurityBundle\ContentSecurityPolicy\PolicyManager;
use Nelmio\SecurityBundle\EventListener\ContentSecurityPolicyListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class ContentSecurityPolicyListenerTest extends \PHPUnit\Framework\TestCase
{
    private $kernel;
    private $nonceGenerator;
    private $shaComputer;

    protected function setUp()
    {
        $this->kernel = $this->getMockBuilder('Symfony\Component\HttpKernel\HttpKernelInterface')->getMock();
        $this->nonceGenerator = $this->getMockBuilder('Nelmio\SecurityBundle\ContentSecurityPolicy\NonceGenerator')
            ->disableOriginalConstructor()
            ->getMock();

        $this->shaComputer = $this->getMockBuilder('Nelmio\SecurityBundle\ContentSecurityPolicy\ShaComputer')
            ->disableOriginalConstructor()
            ->getMock();
        $this->shaComputer->expects($this->any())
            ->method('computeForScript')
            ->will($this->returnValue('sha-script'));
        $this->shaComputer->expects($this->any())
            ->method('computeForStyle')
            ->will($this->returnValue('sha-style'));
    }

    /**
     * @group legacy
     * @expectedDeprecation Retrieving a nonce without a usage is deprecated since version 2.4, and will be removed in version 3
     */
    public function testDeprecationNotice()
    {
        $listener = $this->buildSimpleListener(['default-src' => "default.example.org 'self'"]);
        $listener->getNonce();
    }

    /**
     * @expectedException \Invalid usage provided
     */
    public function tesInvalidArgumentException()
    {
        $listener = $this->buildSimpleListener(['default-src' => "default.example.org 'self'"]);
        $listener->getNonce('prout');
    }

    public function testDefault()
    {
        $listener = $this->buildSimpleListener(['default-src' => "default.example.org 'self'"]);
        $response = $this->callListener($listener, '/', true);

        $this->assertEquals(
            "default-src default.example.org 'self'",
            $response->headers->get('Content-Security-Policy')
        );
    }

    public function testDefaultWithSignatures()
    {
        $listener = $this->buildSimpleListener(['default-src' => "default.example.org 'self'"]);
        $response = $this->callListener($listener, '/', true, 'text/html', ['signatures' => ['script-src' => ['sha-1']]]);

        $this->assertEquals(
            "default-src default.example.org 'self'; script-src default.example.org 'self' 'unsafe-inline' 'sha-1'",
            $response->headers->get('Content-Security-Policy')
        );
    }

    public function testEvenWithUnsafeInlineItAppliesSignature()
    {
        $listener = $this->buildSimpleListener(['default-src' => "default.example.org 'self'", 'script-src' => "'self' 'unsafe-inline'"]);
        $response = $this->callListener($listener, '/', true, 'text/html', ['signatures' => ['script-src' => ['sha-1']]]);

        $this->assertEquals(
            "default-src default.example.org 'self'; script-src 'self' 'unsafe-inline' 'sha-1'",
            $response->headers->get('Content-Security-Policy')
        );
    }

    public function testDefaultWithSignaturesAndNonce()
    {
        $this->nonceGenerator->expects($this->any())
            ->method('generate')
            ->will($this->returnValue('12345'));

        $listener = $this->buildSimpleListener(['default-src' => "default.example.org 'self'"]);
        $response = $this->callListener($listener, '/', true, 'text/html', ['signatures' => ['script-src' => ['sha-1']]], 3);

        $this->assertEquals(
            "default-src default.example.org 'self'; script-src default.example.org 'self' 'unsafe-inline' 'sha-1' 'nonce-12345'; style-src default.example.org 'self' 'unsafe-inline' 'nonce-12345'",
            $response->headers->get('Content-Security-Policy')
        );
    }

    public function testDefaultWithAddScript()
    {
        $listener = $this->buildSimpleListener(['default-src' => "default.example.org 'self'"]);
        $response = $this->callListener($listener, '/', true, 'text/html', ['scripts' => ['<script></script>'], 'styles' => ['<style></style>']], 3);

        $this->assertEquals(
            "default-src default.example.org 'self'; script-src default.example.org 'self' 'unsafe-inline' 'sha-script'; style-src default.example.org 'self' 'unsafe-inline' 'sha-style'",
            $response->headers->get('Content-Security-Policy')
        );
    }

    public function testWithContentTypeRestriction()
    {
        $listener = $this->buildSimpleListener(['default-src' => "default.example.org 'self'"], false, true, ['text/html']);
        $response = $this->callListener($listener, '/', true, 'application/json');

        $this->assertEquals(null, $response->headers->get('Content-Security-Policy'));
    }

    public function testScript()
    {
        $script = "script.example.org 'self' 'unsafe-eval' 'strict-dynamic' 'unsafe-inline'";

        $listener = $this->buildSimpleListener(['script-src' => $script]);
        $response = $this->callListener($listener, '/', true);
        $this->assertEquals(
            "script-src script.example.org 'self' 'unsafe-eval' 'strict-dynamic' 'unsafe-inline'",
            $response->headers->get('Content-Security-Policy')
        );
    }

    public function testObject()
    {
        $object = "object.example.org 'self'";

        $listener = $this->buildSimpleListener(['object-src' => $object]);
        $response = $this->callListener($listener, '/', true);
        $this->assertEquals("object-src object.example.org 'self'", $response->headers->get('Content-Security-Policy'));
    }

    public function testStyle()
    {
        $style = "style.example.org 'self'";

        $listener = $this->buildSimpleListener(['style-src' => $style]);
        $response = $this->callListener($listener, '/', true);
        $this->assertEquals("style-src style.example.org 'self'", $response->headers->get('Content-Security-Policy'));
    }

    public function testImg()
    {
        $img = "img.example.org 'self'";

        $listener = $this->buildSimpleListener(['img-src' => $img]);
        $response = $this->callListener($listener, '/', true);
        $this->assertEquals("img-src img.example.org 'self'", $response->headers->get('Content-Security-Policy'));
    }

    public function testMedia()
    {
        $media = "media.example.org 'self'";

        $listener = $this->buildSimpleListener(['media-src' => $media]);
        $response = $this->callListener($listener, '/', true);
        $this->assertEquals("media-src media.example.org 'self'", $response->headers->get('Content-Security-Policy'));
    }

    public function testFrame()
    {
        $frame = "frame.example.org 'self'";

        $listener = $this->buildSimpleListener(['frame-src' => $frame]);
        $response = $this->callListener($listener, '/', true);
        $this->assertEquals("frame-src frame.example.org 'self'", $response->headers->get('Content-Security-Policy'));
    }

    public function testFont()
    {
        $font = "font.example.org 'self'";

        $listener = $this->buildSimpleListener(['font-src' => $font]);
        $response = $this->callListener($listener, '/', true);
        $this->assertEquals("font-src font.example.org 'self'", $response->headers->get('Content-Security-Policy'));
    }

    public function testConnect()
    {
        $connect = "connect.example.org 'self'";

        $listener = $this->buildSimpleListener(['connect-src' => $connect]);
        $response = $this->callListener($listener, '/', true);
        $this->assertEquals(
            "connect-src connect.example.org 'self'",
            $response->headers->get('Content-Security-Policy')
        );
    }

    public function testReportUri()
    {
        $reportUri = 'http://example.org/CSPReport';

        $listener = $this->buildSimpleListener(['report-uri' => $reportUri]);
        $response = $this->callListener($listener, '/', true);
        $this->assertEquals(
            'report-uri http://example.org/CSPReport',
            $response->headers->get('Content-Security-Policy')
        );
    }

    public function testEmpty()
    {
        $listener = $this->buildSimpleListener([]);
        $response = $this->callListener($listener, '/', true);
        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    public function testAll()
    {
        $reportUri = 'http://example.org/CSPReport';

        $listener = $this->buildSimpleListener([
            'default-src' => "example.org 'self'",
            'script-src' => "script.example.org 'self'",
            'object-src' => "object.example.org 'self'",
            'style-src' => "style.example.org 'self'",
            'img-src' => "img.example.org 'self'",
            'media-src' => "media.example.org 'self'",
            'frame-src' => "frame.example.org 'self'",
            'font-src' => "font.example.org 'self'",
            'connect-src' => "connect.example.org 'self'",
            'report-uri' => $reportUri,
            'base-uri' => "base-uri.example.org 'self'",
            'child-src' => "child-src.example.org 'self'",
            'form-action' => "form-action.example.org 'self'",
            'frame-ancestors' => "frame-ancestors.example.org 'self'",
            'plugin-types' => 'application/shockwave-flash',
            'block-all-mixed-content' => true,
            'upgrade-insecure-requests' => true,
        ]);
        $response = $this->callListener($listener, '/', true);

        $header = $response->headers->get('Content-Security-Policy');

        if (method_exists($this, 'assertStringContainsString')) {
            $assertMethod = 'assertStringContainsString';
        } else {
            $assertMethod = 'assertContains';
        }

        $this->{$assertMethod}("default-src example.org 'self'", $header, 'Header should contain default-src');
        $this->{$assertMethod}("script-src script.example.org 'self'", $header, 'Header should contain script-src');
        $this->{$assertMethod}("object-src object.example.org 'self'", $header, 'Header should contain object-src');
        $this->{$assertMethod}("style-src style.example.org 'self'", $header, 'Header should contain style-src');
        $this->{$assertMethod}("img-src img.example.org 'self'", $header, 'Header should contain img-src');
        $this->{$assertMethod}("media-src media.example.org 'self'", $header, 'Header should contain media-src');
        $this->{$assertMethod}("frame-src frame.example.org 'self'", $header, 'Header should contain frame-src');
        $this->{$assertMethod}("font-src font.example.org 'self'", $header, 'Header should contain font-src');
        $this->{$assertMethod}("connect-src connect.example.org 'self'", $header, 'Header should contain connect-src');
        $this->{$assertMethod}('report-uri http://example.org/CSPReport', $header, 'Header should contain report-uri');
        $this->{$assertMethod}("base-uri base-uri.example.org 'self'", $header, 'Header should contain base-uri');
        $this->{$assertMethod}("child-src child-src.example.org 'self'", $header, 'Header should contain child-src');
        $this->{$assertMethod}("form-action form-action.example.org 'self'", $header, 'Header should contain form-action');
        $this->{$assertMethod}("frame-ancestors frame-ancestors.example.org 'self'", $header, 'Header should contain frame-ancestors');
        $this->{$assertMethod}('plugin-types application/shockwave-flash', $header, 'Header should contain plugin-types');
        $this->{$assertMethod}('block-all-mixed-content', $header, 'Header should contain block-all-mixed-content');
        $this->{$assertMethod}('upgrade-insecure-requests', $header, 'Header should contain upgrade-insecure-requests');
    }

    public function testDelimiter()
    {
        $spec = 'example.org';
        $listener = $this->buildSimpleListener([
            'default-src' => "default.example.org 'self'",
            'script-src' => "script.example.org 'self'",
            'object-src' => "object.example.org 'self'",
            'style-src' => "style.example.org 'self'",
            'img-src' => "img.example.org 'self'",
            'media-src' => "media.example.org 'self'",
            'frame-src' => "frame.example.org 'self'",
            'font-src' => "font.example.org 'self'",
            'connect-src' => "connect.example.org 'self'",
        ]);
        $response = $this->callListener($listener, '/', true);

        $header = $response->headers->get('Content-Security-Policy');

        $this->assertSame(
            "default-src default.example.org 'self'; script-src script.example.org 'self'; ".
            "object-src object.example.org 'self'; style-src style.example.org 'self'; ".
            "img-src img.example.org 'self'; media-src media.example.org 'self'; ".
            "frame-src frame.example.org 'self'; font-src font.example.org 'self'; ".
            "connect-src connect.example.org 'self'",
            $header,
            'The header should contain all directives separated by a semicolon'
        );
    }

    public function testAvoidDuplicates()
    {
        $spec = 'example.org';
        $listener = $this->buildSimpleListener([
            'default-src' => $spec,
            'script-src' => $spec,
            'object-src' => $spec,
            'style-src' => $spec,
            'img-src' => $spec,
            'media-src' => $spec,
            'frame-src' => $spec,
            'font-src' => $spec,
            'connect-src' => $spec,
        ]);
        $response = $this->callListener($listener, '/', true);

        $header = $response->headers->get('Content-Security-Policy');

        $this->assertEquals(
            'default-src example.org',
            $header,
            'Response should contain only the default as the others are equivalent'
        );
    }

    public function testVendorPrefixes()
    {
        $spec = 'example.org';
        $listener = $this->buildSimpleListener([
            'default-src' => $spec,
            'script-src' => $spec,
            'object-src' => $spec,
            'style-src' => $spec,
            'img-src' => $spec,
            'media-src' => $spec,
            'frame-src' => $spec,
            'font-src' => $spec,
            'connect-src' => $spec,
        ]);
        $response = $this->callListener($listener, '/', true);

        $this->assertEquals(
            $response->headers->get('Content-Security-Policy'),
            $response->headers->get('X-Content-Security-Policy'),
            'Response should contain non-standard X-Content-Security-Policy header'
        );
    }

    public function testReportOnly()
    {
        $spec = 'example.org';
        $listener = $this->buildSimpleListener([
            'default-src' => $spec,
            'script-src' => $spec,
            'object-src' => $spec,
            'style-src' => $spec,
            'img-src' => $spec,
            'media-src' => $spec,
            'frame-src' => $spec,
            'font-src' => $spec,
            'connect-src' => $spec,
        ], true);
        $response = $this->callListener($listener, '/', true);

        $this->assertNull($response->headers->get('Content-Security-Policy'));
        $this->assertNotNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function testNoCompatHeaders()
    {
        $spec = 'example.org';
        $listener = $this->buildSimpleListener([
            'default-src' => $spec,
            'script-src' => $spec,
            'object-src' => $spec,
            'style-src' => $spec,
            'img-src' => $spec,
            'media-src' => $spec,
            'frame-src' => $spec,
            'font-src' => $spec,
            'connect-src' => $spec,
        ], false, false);
        $response = $this->callListener($listener, '/', true);

        $this->assertNull($response->headers->get('X-Content-Security-Policy'));
        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
    }

    public function testDirectiveSetUnset()
    {
        $directiveSet = new DirectiveSet(new PolicyManager());
        $directiveSet->setDirectives(['default-src' => 'foo']);
        $this->assertEquals('default-src foo', $directiveSet->buildHeaderValue(new Request()));
        $directiveSet->setDirective('default-src', '');
        $this->assertEquals('', $directiveSet->buildHeaderValue(new Request()));
    }

    protected function buildSimpleListener(array $directives, $reportOnly = false, $compatHeaders = true, $contentTypes = [])
    {
        $directiveSet = new DirectiveSet(new PolicyManager());
        $directiveSet->setDirectives($directives);

        if ($reportOnly) {
            return new ContentSecurityPolicyListener($directiveSet, new DirectiveSet(new PolicyManager()), $this->nonceGenerator, $this->shaComputer, $compatHeaders, $contentTypes);
        } else {
            return new ContentSecurityPolicyListener(new DirectiveSet(new PolicyManager()), $directiveSet, $this->nonceGenerator, $this->shaComputer, $compatHeaders, $contentTypes);
        }
    }

    protected function callListener(ContentSecurityPolicyListener $listener, $path, $masterReq, $contentType = 'text/html', array $digestData = [], $getNonce = 0)
    {
        $request = Request::create($path);

        if (class_exists('Symfony\Component\HttpKernel\Event\RequestEvent')) {
            $class = 'Symfony\Component\HttpKernel\Event\RequestEvent';
        } else {
            $class = 'Symfony\Component\HttpKernel\Event\GetResponseEvent';
        }

        $event = new $class(
            $this->kernel,
            $request,
            $masterReq ? HttpKernelInterface::MASTER_REQUEST : HttpKernelInterface::SUB_REQUEST
        );

        $listener->onKernelRequest($event);

        if (isset($digestData['scripts'])) {
            foreach ($digestData['scripts'] as $script) {
                $listener->addScript($script);
            }
        }
        if (isset($digestData['styles'])) {
            foreach ($digestData['styles'] as $style) {
                $listener->addStyle($style);
            }
        }

        if (isset($digestData['signatures'])) {
            foreach ($digestData['signatures'] as $type => $values) {
                foreach ($values as $value) {
                    $listener->addSha($type, $value);
                }
            }
        }

        for ($i = 0; $i < $getNonce; ++$i) {
            $listener->getNonce('script');
            $listener->getNonce('style');
        }

        $response = new Response();
        $response->headers->add(['content-type' => $contentType]);

        if (class_exists('Symfony\Component\HttpKernel\Event\ResponseEvent')) {
            $class = 'Symfony\Component\HttpKernel\Event\ResponseEvent';
        } else {
            $class = 'Symfony\Component\HttpKernel\Event\FilterResponseEvent';
        }

        $event = new $class(
            $this->kernel,
            $request,
            $masterReq ? HttpKernelInterface::MASTER_REQUEST : HttpKernelInterface::SUB_REQUEST,
            $response
        );
        $listener->onKernelResponse($event);

        return $response;
    }
}