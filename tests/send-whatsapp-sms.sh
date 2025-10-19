curl -X 'POST' \
  'http://localhost:3000/api/sendText' \
  -H 'accept: application/json' \
  -H 'Content-Type: application/json' \
  -H "X-Api-Key: 293d2fb3e47a47eaa1ffe825f90ea4eb" \
  -d '{
  "chatId": "971543998492@c.us",
  "text": "Hi there!",
  "session": "default"
}'
