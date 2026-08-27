<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\CredencialPagamento;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * A validacao do formulario de credenciais.
 *
 * Duas coisas merecem explicacao:
 *
 * 1. **Todo campo e opcional.** Nao e frouxidao: como nenhum valor guardado
 *    volta para a tela, o formulario chega sempre com os campos em branco, e
 *    branco quer dizer "mantem o que esta la". Exigir preenchimento obrigaria
 *    a redigitar a credencial inteira para corrigir uma letra da chave Pix.
 *
 * 2. **O certificado precisa abrir de verdade.** Conferir so a extensao
 *    deixaria passar um arquivo trocado, um .p12 corrompido pelo caminho ou o
 *    PDF do contrato renomeado. O erro apareceria semanas depois, na primeira
 *    cobranca real, como uma falha de TLS que nao explica nada. Aqui ele
 *    aparece no ato, com o motivo em portugues.
 */
class SalvarCredencialPagamentoRequest extends FormRequest
{
    /**
     * Tamanho maximo do certificado, em kilobytes. Certificado de verdade tem
     * poucos kilobytes; o teto existe para que ninguem tente guardar um
     * arquivo qualquer na coluna.
     */
    private const TAMANHO_MAXIMO_EM_KB = 512;

    public function authorize(): bool
    {
        // Segunda tranca. A rota ja cobra a permissao; se um dia esta tela for
        // registrada em outro grupo e alguem esquecer o middleware, o 403
        // continua acontecendo.
        return $this->user()?->can('pagamentos.credenciais') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['nullable', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:255'],
            'chave_pix' => ['nullable', 'string', 'max:190'],
            // O valor do webhook e comparado com hash_equals a cada aviso da
            // Efi. Um valor curto seria adivinhavel por forca bruta, e quem
            // acertasse conseguiria confirmar inscricao sem pagar.
            'webhook_hmac' => ['nullable', 'string', 'min:16', 'max:190'],
            'certificado' => [
                'nullable',
                'file',
                'max:'.self::TAMANHO_MAXIMO_EM_KB,
                // A extensao e conferida pelo nome do arquivo, e nao pelo tipo
                // declarado pelo navegador: o .p12 e binario e chega com tipo
                // generico em quase todo sistema.
                'extensions:pem,p12,pfx',
                function (string $atributo, mixed $valor, Closure $recusar): void {
                    if (! $valor instanceof UploadedFile) {
                        return;
                    }

                    $conteudo = (string) @file_get_contents((string) $valor->getRealPath());

                    if (! CredencialPagamento::lerCertificado($conteudo)['valido']) {
                        $recusar(
                            'O arquivo enviado nao pode ser lido como certificado. '.
                            'Envie o .p12 baixado do painel da Efi, ou o .pem convertido a partir dele.'
                        );
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'webhook_hmac.min' => 'O valor do webhook precisa de pelo menos 16 caracteres. '.
                'Use o botao de gerar um valor aleatorio.',
            'certificado.file' => 'Envie um arquivo de certificado.',
            'certificado.max' => 'O certificado passou de '.self::TAMANHO_MAXIMO_EM_KB.' KB. '.
                'Confira se o arquivo enviado e mesmo o certificado.',
            'certificado.extensions' => 'O certificado precisa ser um arquivo .p12, .pfx ou .pem.',
        ];
    }

    /**
     * Os quatro campos digitados, ja recortados.
     *
     * @return array<string, string|null>
     */
    public function valoresDigitados(): array
    {
        $limpo = static function (mixed $valor): ?string {
            $valor = is_scalar($valor) ? trim((string) $valor) : '';

            return $valor === '' ? null : $valor;
        };

        return [
            'client_id' => $limpo($this->input('client_id')),
            'client_secret' => $limpo($this->input('client_secret')),
            'chave_pix' => $limpo($this->input('chave_pix')),
            'webhook_hmac' => $limpo($this->input('webhook_hmac')),
        ];
    }

    public function certificadoEnviado(): ?UploadedFile
    {
        $arquivo = $this->file('certificado');

        return $arquivo instanceof UploadedFile ? $arquivo : null;
    }
}
