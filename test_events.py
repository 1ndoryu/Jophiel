import pika
import json
import time
import uuid

# --- Configuración ---
# Por favor, asegúrate de que estos valores coinciden con tu config/rabbitmq.php
RABBITMQ_HOST = 'localhost'
RABBITMQ_PORT = 5672
RABBITMQ_USER = 'user'
RABBITMQ_PASS = 'password'
RABBITMQ_VHOST = '/'
EXCHANGE_NAME = 'sword_events' # El exchange donde Jophiel escucha

# --- Datos de Prueba ---
USER_A_ID = 999999901
USER_B_ID = 999999902 # Actuará como el creador del sample
SAMPLE_ID = 999999901

def get_rabbitmq_connection():
    """Establece y devuelve una conexión con RabbitMQ."""
    credentials = pika.PlainCredentials(RABBITMQ_USER, RABBITMQ_PASS)
    parameters = pika.ConnectionParameters(
        host=RABBITMQ_HOST,
        port=RABBITMQ_PORT,
        virtual_host=RABBITMQ_VHOST,
        credentials=credentials
    )
    return pika.BlockingConnection(parameters)

def dispatch_event(channel, event_name, routing_key, payload):
    """Publica un evento en RabbitMQ."""
    message_body = json.dumps({
        'event_name': event_name,
        'event_id': str(uuid.uuid4()),
        'event_timestamp': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
        'source': 'python_test_script',
        'payload': payload,
    })
    
    channel.basic_publish(
        exchange=EXCHANGE_NAME,
        routing_key=routing_key,
        body=message_body,
        properties=pika.BasicProperties(
            content_type='application/json',
            delivery_mode=2,  # Hacer el mensaje persistente
        )
    )
    print(f"-> Evento '{event_name}' despachado con routing_key '{routing_key}'.")

def main():
    """Función principal que ejecuta la simulación de eventos."""
    try:
        connection = get_rabbitmq_connection()
        channel = connection.channel()
        # Asegurarse de que el exchange existe
        channel.exchange_declare(exchange=EXCHANGE_NAME, exchange_type='topic', durable=True)
        
        print("--- Iniciando Simulación de Eventos con Script de Python ---")

        events_to_simulate = [
            ('sample.lifecycle.created', {'sample_id': SAMPLE_ID, 'creator_id': USER_B_ID, 'metadata': {'tags': ['python', 'test'], 'bpm': 125}}),
            ('sample.lifecycle.updated', {'sample_id': SAMPLE_ID, 'creator_id': USER_B_ID, 'metadata': {'tags': ['python', 'updated'], 'bpm': 130}}),
            ('user.interaction.like', {'user_id': USER_A_ID, 'sample_id': SAMPLE_ID}),
            ('user.interaction.unlike', {'user_id': USER_A_ID, 'sample_id': SAMPLE_ID}),
            ('user.interaction.comment', {'user_id': USER_A_ID, 'sample_id': SAMPLE_ID}),
            ('user.interaction.follow', {'user_id': USER_A_ID, 'followed_user_id': USER_B_ID}),
            ('user.interaction.unfollow', {'user_id': USER_A_ID, 'unfollowed_user_id': USER_B_ID}),
            ('sample.lifecycle.deleted', {'sample_id': SAMPLE_ID}),
        ]

        for event_name, payload in events_to_simulate:
            routing_key = event_name
            dispatch_event(channel, event_name, routing_key, payload)
            time.sleep(1.5) # Pausa para dar tiempo al consumidor a procesar

        print("\n--- Simulación de Eventos Completada ---")
        print("Verifica los logs de Jophiel ('storage/logs/rabbitmq-consumer.log') para ver el procesamiento.")

    except Exception as e:
        print(f"\nError: No se pudo conectar o despachar a RabbitMQ.")
        print(f"Detalle: {e}")
        print("Por favor, verifica que RabbitMQ esté corriendo y que la configuración en este script sea correcta.")
    finally:
        if 'connection' in locals() and connection.is_open:
            connection.close()
            print("\nConexión a RabbitMQ cerrada.")

if __name__ == "__main__":
    main() 