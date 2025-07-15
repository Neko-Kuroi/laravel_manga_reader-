<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Manga;

class StoreMangaRequest extends FormRequest
{
    const ALLOWED_DOMAINS = ['example.com', 'trusted-site.com']; // 許可されたドメイン

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Implement authorization logic if needed, e.g., check if user is authenticated
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'manga_url' => [
                'required',
                'url',
                function ($attribute, $value, $fail) {
                    $url = urldecode($value);

                    // URL形式の再検証 (filter_var(URL)は厳しすぎる場合があるため、parse_urlで補強)
                    $parsed = parse_url($url);
                    if (!$parsed || !isset($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'])) {
                        $fail('無効なURL形式です。');
                        return;
                    }

                    // 許可されたドメインのチェック
                    $domain = $parsed['host'] ?? null;
                    if (!empty(self::ALLOWED_DOMAINS) && !in_array($domain, self::ALLOWED_DOMAINS)) {
                        $fail('許可されていないドメインです。');
                        return;
                    }

                    // SSRF対策: 内部IPアドレスのチェック
                    try {
                        $ip = gethostbyname($domain);
                        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                            $fail('内部IPアドレスへのアクセスは許可されていません。');
                            return;
                        }
                    } catch (\Throwable $e) {
                        $fail('URLの解決に失敗しました。');
                        return;
                    }

                    // ファイル拡張子のチェック
                    $ext = strtolower(pathinfo($url, PHP_URL_EXTENSION));
                    if (!in_array($ext, ['zip', 'cbz', 'rar', 'cbr'])) {
                        $fail('無効なファイル��式です。ZIP, CBZ, RAR, CBRのみが許可されています。');
                        return;
                    }

                    // 重複チェック
                    if (Manga::where('hash', md5($url))->exists()) {
                        $fail('このURLは既に追加済みです。');
                        return;
                    }
                },
            ],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'manga_url.required' => 'URLは必須です。',
            'manga_url.url' => '有効なURL形式で入力してください。',
        ];
    }
}