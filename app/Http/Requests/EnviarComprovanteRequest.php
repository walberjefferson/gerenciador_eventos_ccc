<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * A validacao do comprovante que o participante envia.
 *
 * **O tipo do arquivo e conferido pelo CONTEUDO, nunca pela extensao.** A regra
 * e `mimetypes`, e nao `mimes`: `mimes` olha o nome do arquivo, e o nome quem
 * escolhe e quem envia. Um executavel chamado "comprovante.jpg" passaria por
 * `mimes` e nao passa por aqui, porque `mimetypes` manda o servidor abrir o
 * arquivo e perguntar ao sistema o que ele e de fato.
 *
 * Nao ha `authorize()` proprio: a rota e assinada, e o middleware "signed" ja
 * respondeu 403 a quem chegou sem a assinatura valida — antes de este objeto
 * existir.
 */
class EnviarComprovanteRequest extends FormRequest
{
    /**
     * Cinco megabytes. Uma foto de tela de celular tem menos de dois; o teto
     * existe para que ninguem use esta porta como deposito de arquivo.
     */
    public const TAMANHO_MAXIMO_EM_KB = 5120;

    /**
     * Os quatro tipos que valem como comprovante: as tres imagens que um
     * celular tira e o PDF que o aplicativo do banco exporta.
     *
     * @var array<int, string>
     */
    public const TIPOS_ACEITOS = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'comprovante' => [
                'required',
                'file',
                'max:'.self::TAMANHO_MAXIMO_EM_KB,
                'mimetypes:'.implode(',', self::TIPOS_ACEITOS),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'comprovante.required' => 'Escolha o arquivo do comprovante para enviar.',
            'comprovante.file' => 'Escolha o arquivo do comprovante para enviar.',
            'comprovante.max' => 'O arquivo passou de 5 MB. Envie uma foto menor ou o PDF do comprovante.',
            'comprovante.mimetypes' => 'O comprovante precisa ser uma imagem (JPG, PNG ou WebP) ou um PDF. '
                .'Trocar a extensão do arquivo não funciona: o servidor confere o conteúdo.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'comprovante' => 'comprovante',
        ];
    }

    public function arquivoEnviado(): UploadedFile
    {
        /** @var UploadedFile $arquivo */
        $arquivo = $this->file('comprovante');

        return $arquivo;
    }
}
