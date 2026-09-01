{{-- O ingresso impresso.

     Desenhado para o pior cenario possivel, que e o mais provavel: papel
     dobrado, impressora preto e branco sem tinta sobrando e uma fila andando.
     Por isso nada de fundo colorido, nada de imagem decorativa e o codigo
     escrito em letra grande LOGO ABAIXO do QR — se o desenho borrar, quem
     esta no portao ainda consegue digitar.

     O dompdf entende um subconjunto pequeno de CSS: aqui so ha tabela,
     margem, borda e tamanho de letra. Nada de flexbox, nada de grid. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Ingresso — {{ $evento?->nome }}</title>
    <style>
        @page { margin: 24mm 18mm; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #1a1a1a; font-size: 12px; line-height: 1.5; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .ingresso { border: 2px solid #1a1a1a; padding: 18px; }
        .evento { font-size: 13px; color: #444444; margin: 0 0 18px; }
        .codigo { font-size: 26px; letter-spacing: 3px; font-weight: bold; margin: 10px 0 0; }
        .rotulo { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #555555; margin: 0; }
        .dado { font-size: 14px; font-weight: bold; margin: 0 0 12px; }
        .rodape { margin-top: 18px; padding-top: 12px; border-top: 1px solid #cccccc; color: #555555; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }
        .qr { width: 190px; text-align: center; }
        .qr img { width: 180px; height: 180px; }
    </style>
</head>
<body>
    <div class="ingresso">
        <h1>Ingresso</h1>
        <p class="evento">{{ $evento?->nome }}</p>

        <table>
            <tr>
                <td>
                    <p class="rotulo">Participante</p>
                    <p class="dado">{{ $inscricao?->nome_completo }}</p>

                    @if ($evento?->local)
                        <p class="rotulo">Local</p>
                        <p class="dado">{{ $evento->local }}</p>
                    @endif

                    @if ($evento?->data_inicio)
                        <p class="rotulo">Quando</p>
                        <p class="dado">
                            {{ $evento->data_inicio->format('d/m/Y') }}
                            @if ($evento->data_fim && $evento->data_fim->notEqualTo($evento->data_inicio))
                                a {{ $evento->data_fim->format('d/m/Y') }}
                            @endif
                        </p>
                    @endif

                    <p class="rotulo">Inscrição</p>
                    <p class="dado">{{ $inscricao?->codigo_publico }}</p>
                </td>

                <td class="qr">
                    {{-- PNG embutido em "data:" URI: o dompdf nao e confiavel
                         com SVG, e falha em silencio quando nao consegue. --}}
                    <img src="{{ $qrCode }}" alt="QR Code do ingresso">
                    <p class="rotulo">Código do ingresso</p>
                    <p class="codigo">{{ $codigoFormatado }}</p>
                </td>
            </tr>
        </table>

        <p class="rodape">
            Apresente este ingresso na entrada. Ele vale para <strong>uma única</strong> entrada e é
            pessoal: depois de registrado no portão, a mesma leitura não é aceita de novo.
            Se o desenho não for lido, informe o código escrito acima.
        </p>
    </div>
</body>
</html>
