<?php

namespace Tests;

class ExampleTest extends TestCase
{
    public function test_root_reports_service_status(): void
    {
        $this->get('/');

        $this->assertResponseStatus(200);
        $this->seeJson([
            'service' => 'vk-chatbot',
            'status' => 'ok',
        ]);
    }
}
