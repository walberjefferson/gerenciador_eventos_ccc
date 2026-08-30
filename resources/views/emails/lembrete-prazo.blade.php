@extends('emails.moldura')

@section('titulo', 'O prazo está acabando')

@section('conteudo')
    <p style="margin:0 0 16px;">Olá, {{ $nome }}!</p>

    <p style="margin:0 0 16px;">
        A sua vaga em <strong>{{ $evento }}</strong> continua guardada, mas o pagamento ainda não
        chegou até nós. <strong>{{ $tempoRestante }}</strong> para pagar.
    </p>

    <div style="margin:0 0 16px;padding:16px;border:1px solid #e3e3e3;border-radius:8px;">
        <p style="margin:0 0 4px;">Valor: <strong>{{ $valor }}</strong></p>
        <p style="margin:0;">Prazo: <strong>{{ $prazo }}</strong>.</p>
    </div>

    <p style="margin:0 0 16px;">
        <a href="{{ $link }}" style="color:#0b6bb3;">Pagar agora</a>
    </p>

    <p style="margin:0;color:#555555;">
        Passado esse horário, a vaga volta para a fila e fica disponível para outra pessoa.
        Se você já pagou, pode ignorar esta mensagem: o reconhecimento do pagamento pode levar
        alguns minutos, e avisamos assim que ele acontecer.
    </p>
@endsection
