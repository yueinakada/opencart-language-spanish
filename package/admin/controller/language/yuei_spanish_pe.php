<?php
declare(strict_types=1);

namespace Opencart\Admin\Controller\Extension\YueiSpanishPe\Language;

use Opencart\System\Library\Extension\YueiSpanishPe\Installer;

class YueiSpanishPe extends \Opencart\System\Engine\Controller {
    private const ROUTE = 'extension/yuei_spanish_pe/language/yuei_spanish_pe';

    public function index(): void {
        $this->load->language(self::ROUTE);
        $this->headers();
        $this->document->setTitle($this->language->get('heading_title'));
        $params = 'user_token=' . $this->session->data['user_token'];
        $data = [
            'languages' => $this->url->link('localisation/language', $params, true),
            'settings' => $this->url->link('setting/setting', $params, true),
            'back' => $this->url->link('marketplace/extension', $params . '&type=language', true)
        ];
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view(self::ROUTE, $data));
    }

    private function authorize(): void {
        $provided = $this->request->get['user_token'] ?? null;
        $token = $this->session->data['user_token'] ?? null;
        if (!$this->user->isLogged() || !$this->user->hasPermission('modify', 'extension/language') ||
            !is_string($provided) || !is_string($token) || !hash_equals($token, $provided)) {
            throw new \RuntimeException('Permission denied');
        }
        $this->headers();
    }

    public function install(): void {
        $this->authorize();
        Installer::connect()->install((int)$this->config->get('config_language_id'));
        $this->cache->delete('language');
    }

    public function uninstall(): void {
        $this->authorize();
        Installer::connect()->uninstall();
        $this->cache->delete('language');
    }

    // Framework blocks direct HTTP calls to methods beginning with an underscore.
    public function _guardLanguage(string &$route, array &$args): void {
        if (($this->request->get['extension'] ?? null) === Installer::PACKAGE &&
            ($this->request->get['code'] ?? null) === Installer::PACKAGE && Installer::connect()->isDefault()) {
            $route = self::ROUTE . '.blockedDefault';
        }
    }

    public function _guardPackage(string &$route, array &$args): void {
        $id = $this->request->get['extension_install_id'] ?? '';
        if (!is_string($id) || !ctype_digit($id) || strlen($id) > 10) { return; }
        $installer = Installer::connect();
        if (!$installer->packageIdMatches((int)$id)) { return; }
        if ($installer->language()) {
            $route = self::ROUTE . '.blockedPackage';
        } elseif ($this->user->hasPermission('modify', 'marketplace/installer')) {
            $installer->cleanup();
        }
    }

    public function blockedDefault(): void { $this->blocked('error_default'); }
    public function blockedPackage(): void { $this->blocked('error_package'); }

    private function blocked(string $key): void {
        $this->load->language(self::ROUTE);
        $this->headers();
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->setOutput(json_encode(['error' => $this->language->get($key)], JSON_THROW_ON_ERROR));
    }

    private function headers(): void {
        $this->response->addHeader('X-Content-Type-Options: nosniff');
        $this->response->addHeader('X-Frame-Options: SAMEORIGIN');
        $this->response->addHeader("Content-Security-Policy: base-uri 'self'; object-src 'none'; frame-ancestors 'self'");
        $this->response->addHeader('Cache-Control: no-store');
        if (str_starts_with(HTTP_SERVER, 'https://')) {
            $this->response->addHeader('Strict-Transport-Security: max-age=31536000');
        }
    }
}
