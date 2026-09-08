<?php

namespace Pivel\Hydro2\Models\HTTP;

class Response
{
    
    protected string $content;
    protected bool $final;
    protected StatusCode $status;
    /**
     * @var string[]
     */
    protected array $headers;
    
    public function __construct(string $content='', bool $final=true, StatusCode $status=StatusCode::OK, array $headers=[])
    {
        $this->content = $content;
        $this->final = $final;
        $this->status = $status;
        $this->headers = $headers;
    }

    public static function GetEmpty() : Response {
        return new static('', false, StatusCode::OK, []);
    }
    
    public function send(bool $buffer=true, bool $is_cli=false)
    {
        if ($is_cli) {
            echo $this->content;
            return;
        }
        
        if (!$buffer) {
            http_response_code($this->status->value);
            foreach ($this->headers as $headerName => $headerContent) {
                // TODO: should probably sanitize the header before sending it.
                header($headerName . ": " . $headerContent);
            }
            echo $this->content;
            return;
        }
        
        ob_end_clean();
        ignore_user_abort(true);
        ob_start();
        
        echo $this->content;
        
        $size = ob_get_length();
        http_response_code($this->status->value);
        header("Content-Length: {$size}");
        foreach ($this->headers as $headerName => $headerContent) {
            // TODO: should probably sanitize the header before sending it.
            header($headerName . ": " . $headerContent);
        }
        ob_end_flush();
        flush();
    }
    
    public function setContent(string $content)
    {
        $this->content = $content;
    }
    
    public function getContent()
    {
        return $this->content;
    }
    
    public function setFinal(bool $isFinal)
    {
        $this->final = $isFinal;
    }
    
    public function isFinal()
    {
        return $this->final;
    }

    public function getStatus() : StatusCode
    {
        return $this->status;
    }

    public function getHeaders() : array
    {
        return $this->headers;
    }

    public function isEmpty() : bool
    {
        return $this->content === '' && $this->final === false && $this->status === StatusCode::OK && count($this->headers) == 0;
    }
    
    public function append(Response $otherResponse) : void
    {
        if ($this->final) {
            return;
        }
        $this->content .= $otherResponse->getContent();
        $this->final = $otherResponse->isFinal();
        $this->status = $otherResponse->getStatus();
        $this->headers = array_merge($this->headers, $otherResponse->getHeaders());
    }
}