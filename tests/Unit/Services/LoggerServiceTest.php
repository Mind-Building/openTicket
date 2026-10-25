<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\LoggerService;

class LoggerServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        $logPath = LoggerService::getLogPath();
        $logFiles = glob($logPath . '/*.log');
        foreach ($logFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }
    
    public function testLogPathExists(): void
    {
        $logPath = LoggerService::getLogPath();
        $this->assertNotEmpty($logPath);
    }
    
    public function testLogCustomerDoesNotThrowException(): void
    {
        $this->expectNotToPerformAssertions();
        LoggerService::logCustomer('test_action', ['key' => 'value']);
    }
    
    public function testLogAdminDoesNotThrowException(): void
    {
        $this->expectNotToPerformAssertions();
        LoggerService::logAdmin('admin_action', ['admin_id' => '1']);
    }
    
    public function testLogPresalesDoesNotThrowException(): void
    {
        $this->expectNotToPerformAssertions();
        LoggerService::logPresales('presales_action', ['user_id' => '1']);
    }
    
    public function testLogSessionCreatedDoesNotThrowException(): void
    {
        $this->expectNotToPerformAssertions();
        LoggerService::logSessionCreated('session-123', 'user-123');
    }
    
    public function testLogSessionDestroyedDoesNotThrowException(): void
    {
        $this->expectNotToPerformAssertions();
        LoggerService::logSessionDestroyed('session-123', 'user-123', 'logout');
    }
    
    public function testLogSeatsAddedToCartDoesNotThrowException(): void
    {
        $this->expectNotToPerformAssertions();
        LoggerService::logSeatsAddedToCart('session-123', 10, '2026-01-25', ['A1', 'A2'], 'user-123');
    }
    
    public function testLogSeatsReleasedDoesNotThrowException(): void
    {
        $this->expectNotToPerformAssertions();
        LoggerService::logSeatsReleased('session-123', 10, '2026-01-25', ['B5'], 'user-123', 'timeout');
    }
    
    public function testLogVoucherAppliedDoesNotThrowException(): void
    {
        $this->expectNotToPerformAssertions();
        LoggerService::logVoucherApplied('session-123', 'SAVE10', 10.50, 'user-123');
    }
    
    public function testLogPaymentCompletedDoesNotThrowException(): void
    {
        $this->expectNotToPerformAssertions();
        LoggerService::logPaymentCompleted('session-123', 'order-123', 99.99, 'EUR', 'user-123');
    }
    
    public function testLogAdminLoginSuccessDoesNotThrowException(): void
    {
        $this->expectNotToPerformAssertions();
        LoggerService::logAdminLogin('admin-1', 'admin_user', true);
    }
    
    public function testLogAdminLoginFailedDoesNotThrowException(): void
    {
        $this->expectNotToPerformAssertions();
        LoggerService::logAdminLogin('failed', 'bad_user', false, 'invalid_password');
    }
    
    public function testLogPresalesSaleDoesNotThrowException(): void
    {
        $this->expectNotToPerformAssertions();
        LoggerService::logPresalesSale('user-5', 'Max Mustermann', 150.00, 3, 'cash');
    }
    
    public function testLogPresalesRefundDoesNotThrowException(): void
    {
        $this->expectNotToPerformAssertions();
        LoggerService::logPresalesRefund('user-5', 'Anna Müller', 50.00, 'request');
    }
    
    public function testLogPresalesVoucherCreateDoesNotThrowException(): void
    {
        $this->expectNotToPerformAssertions();
        LoggerService::logPresalesVoucherCreate('user-5', 'GUTSCHEIN2026', 25.00);
    }
    
    public function testLogEmailSentDoesNotThrowException(): void
    {
        $this->expectNotToPerformAssertions();
        LoggerService::logEmailSent('ticket', 'abc123', true, 'user-123');
    }
    
    public function testLogEmailSentFailedDoesNotThrowException(): void
    {
        $this->expectNotToPerformAssertions();
        LoggerService::logEmailSent('voucher', 'xyz789', false, 'user-123', ['reason' => 'smtp']);
    }
    
    public function testLogWithWarningLevelDoesNotThrowException(): void
    {
        $this->expectNotToPerformAssertions();
        LoggerService::logCustomer('warning_test', ['key' => 'value'], 'warning');
    }
    
    public function testLogWithErrorLevelDoesNotThrowException(): void
    {
        $this->expectNotToPerformAssertions();
        LoggerService::logAdmin('error_test', ['key' => 'value'], 'error');
    }
}
