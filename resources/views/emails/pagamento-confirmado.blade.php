@extends('emails.moldura')

@section('titulo', 'Pagamento confirmado')

@section('conteudo')
    <p style="margin:0 0 16px;">Olá, {{ $nome }}!</p>

    <p style="margin:0 0 16px;">
        Recebemos o seu pagamento e a sua inscrição em <strong>{{ $evento }}</strong> está
        <strong>confirmada</strong>. Guarde esta mensagem: ela é o seu comprovante.
    </p>

    <div style="margin:0 0 16px;padding:16px;border:1px solid #e3e3e3;border-radius:8px;">
        <p style="margin:0 0 4px;">Valor pago: <strong>{{ $valor }}</strong></p>
        <p style="margin:0 0 4px;">Pagamento reconhecido em: <strong>{{ $pagoEm }}</strong></p>
        <p style="margin:0;">Inscrição: <strong>{{ $codigo }}</strong></p>
    </div>

    {{-- O ingresso. Vem antes das atividades de proposito: e a unica coisa
         desta mensagem de que a pessoa vai precisar NA HORA, parada na fila do
         portao com o celular na mao.

         O QR vai como ANEXO EMBUTIDO (CID), e nao como "data:" URI: o Gmail
         descarta imagem em base64 no "src" e a pessoa veria um quadrado vazio.
         O codigo tambem esta escrito por extenso logo abaixo, porque muitos
         programas de e-mail so mostram imagem depois que alguem autoriza — e
         quem nao autorizar precisa continuar entrando no evento. --}}
    @if ($codigoIngressoFormatado)
        <div style="margin:0 0 16px;padding:16px;border:1px solid #e3e3e3;border-radius:8px;text-align:center;">
            <p style="margin:0 0 8px;font-weight:bold;">Seu ingresso</p>

            @if ($qrIngresso)
                <img src="{{ $message->embedData($qrIngresso, 'ingresso.png', 'image/png') }}"
                     alt="QR Code do ingresso"
                     width="180" height="180"
                     style="display:block;margin:0 auto 8px;width:180px;height:180px;">
            @endif

            <p style="margin:0 0 4px;font-size:22px;letter-spacing:2px;font-family:'Courier New',Courier,monospace;">
                <strong>{{ $codigoIngressoFormatado }}</strong>
            </p>
            <p style="margin:0;color:#555555;font-size:13px;">
                Apresente este código na entrada. Ele vale para uma única entrada.
            </p>
        </div>
    @endif

    @if (count($atividades) > 0)
        <p style="margin:0 0 8px;">Atividades escolhidas:</p>
        <ul style="margin:0 0 16px;padding-left:20px;">
            @foreach ($atividades as $atividade)
                <li>{{ $atividade }}</li>
            @endforeach
        </ul>
    @endif

    <p style="margin:0 0 16px;">
        <a href="{{ $link }}" style="color:#0b6bb3;">Ver a minha inscrição</a>
    </p>

    <p style="margin:0;color:#555555;">
        Nos vemos lá. Se precisar mudar alguma coisa, procure a organização do evento.
    </p>
@endsection
